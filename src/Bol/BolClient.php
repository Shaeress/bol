<?php
declare(strict_types=1);

namespace App\Bol;

use GuzzleHttp\Client;
use App\Config\Config;
use Psr\Log\LoggerInterface;

final class BolClient
{
    private Client $http;
    private string $apiBase;
    private string $authBase;
    private string $cacheFile;
    private ?LoggerInterface $logger = null;

    public function __construct(?Client $http = null, ?LoggerInterface $logger = null)
    {
        $this->apiBase = rtrim(Config::get('BOL_API_BASE', 'https://api.bol.com'), '/');
        $this->authBase = rtrim(Config::get('BOL_AUTH_BASE', 'https://login.bol.com'), '/');
        $this->http = $http ?? new Client(['timeout' => 30]);
        $this->cacheFile = __DIR__ . '/../../var/cache/bol_token.json';
        $this->logger = $logger;
    }

    public function request(string $method, string $path, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $token = $this->getAccessToken();

        if (array_key_exists('json', $options) && $options['json'] === null) {
            unset($options['json']);
        }

        $baseHeaders = [
            'Authorization' => 'Bearer ' . $token,
        ];

        $callerHeaders = $options['headers'] ?? [];
        $finalHeaders = $callerHeaders + $baseHeaders;

        foreach ($finalHeaders as $k => $v) {
            if ($v === null) unset($finalHeaders[$k]);
        }

        $opts = $options;
        $opts['headers'] = $finalHeaders;

        if ($this->logger) {
            $this->logger->debug('BOL request', [
                'method' => $method,
                'url' => $this->apiBase . $path,
                'headers' => $finalHeaders,
                'has_json' => array_key_exists('json', $options),
                'json' => $options['json'] ?? null,
            ]);
        }

        // --- BEGIN retry wrapper ---
        $retries = (int)($options['retries'] ?? 3);
        $delayMs = (int)($options['retry_delay_ms'] ?? 250);

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                return $this->http->request($method, $this->apiBase . $path, $opts);
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $code = $e->getResponse()?->getStatusCode() ?? 0;

                // Retry on rate-limit or transient server errors
                $retryable = in_array($code, [429, 500, 502, 503, 504], true);
                if ($retryable && $attempt < $retries) {
                    $retryAfter = (int)($e->getResponse()?->getHeaderLine('Retry-After') ?? 0);
                    $sleepMs = $retryAfter > 0 ? $retryAfter * 1000 : $delayMs * ($attempt + 1);
                    if ($this->logger) {
                        $this->logger->warning('BOL transient error, retrying', [
                            'code' => $code,
                            'attempt' => $attempt + 1,
                            'sleep_ms' => $sleepMs,
                            'path' => $path,
                        ]);
                    }
                    usleep($sleepMs * 1000);
                    continue;
                }

                // --- Detailed logging on error ---
                $status = $code;
                $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
                $decoded = json_decode($body, true);
                $prettyJson = $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null;

                $isDebug = $this->logger && (
                    method_exists($this->logger, 'isHandling')
                        ? $this->logger->isHandling(\Monolog\Logger::DEBUG)
                        : true
                );

                $logBody = $isDebug ? ($prettyJson ?? $body) : substr($body, 0, 500);

                // Include request body when debugging
                if ($isDebug && $e->getRequest()) {
                    $reqBody = (string)$e->getRequest()->getBody();
                    $logBody = "Request: {$reqBody}\nResponse: {$logBody}";
                }

                if ($this->logger) {
                    $this->logger->error('BOL API error', [
                        'method' => $method,
                        'path' => $path,
                        'status' => $status,
                        'body' => $logBody,
                    ]);
                }

                throw new \RuntimeException(
                    "BOL API error {$status} {$method} {$path}\n{$logBody}",
                    $status,
                    $e
                );
            }
        }

        // Should never reach here
        throw new \RuntimeException('Unreachable after retry attempts');
    }

    private function getAccessToken(): string
    {
        // try cache
        if (is_file($this->cacheFile)) {
            $data = json_decode((string)file_get_contents($this->cacheFile), true);
            if (is_array($data) && isset($data['access_token'], $data['expires_at']) && time() < (int)$data['expires_at'] - 30) {
                return $data['access_token'];
            }
        }

        // fetch new token
        $basic = base64_encode(Config::get('BOL_CLIENT_ID') . ':' . Config::get('BOL_CLIENT_SECRET'));
        $res = $this->http->post($this->authBase . '/token', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . $basic,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => ['grant_type' => 'client_credentials'],
        ]);

        $json = json_decode((string)$res->getBody(), true);
        if (!isset($json['access_token'], $json['expires_in'])) {
            throw new \RuntimeException('Could not get bol access token');
        }

        @mkdir(dirname($this->cacheFile), 0777, true);
        file_put_contents($this->cacheFile, json_encode([
            'access_token' => $json['access_token'],
            'expires_at' => time() + (int)$json['expires_in'],
        ]));

        return $json['access_token'];
    }
}
