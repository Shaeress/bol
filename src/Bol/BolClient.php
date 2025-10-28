<?php
declare(strict_types=1);

namespace App\Bol;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
        
        $headers = [            
            'Authorization' => 'Bearer ' . $token,
        ];        

        $opts = $options + ['headers' => ($options['headers'] ?? []) + $headers];

        if ($this->logger) {
            $this->logger->debug('BOL request', [
                'method' => $method,
                'url' => $this->apiBase . $path,
                'headers' => $headers,
                'has_json' => array_key_exists('json', $options),
                'json' => $options['json'] ?? null,
            ]);
        }

        try {
            return $this->http->request($method, $this->apiBase . $path, $opts);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $status = $e->getCode();
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $shortBody = substr($body, 0, 500);

            $isDebug = $this->logger && method_exists($this->logger, 'isHandling') && $this->logger->isHandling(\Monolog\Logger::DEBUG);

            $logBody = $isDebug ? $body : $shortBody;

            if ($isDebug) {
                //Attach full request to $logBody
                $request = $e->getRequest();
                $requestBody = (string) $request->getBody();
                $logBody = "Request: {$requestBody}\nResponse: {$logBody}";
            }

            if ($this->logger) {
                $this->logger->error('BOL API error', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'body' => $isDebug ? json_decode($logBody, true) ?? $logBody : $logBody,
                ]);
            }

            throw new \RuntimeException(
                "BOL API error {$status} {$method} {$path}\n{$logBody}",
                $status,
                $e
            );
        }
    }

    private function getAccessToken(): string
    {
        // try cache
        if (is_file($this->cacheFile)) {
            $data = json_decode((string) file_get_contents($this->cacheFile), true);
            if (is_array($data) && isset($data['access_token'], $data['expires_at']) && time() < (int) $data['expires_at'] - 30) {
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

        $json = json_decode((string) $res->getBody(), true);
        if (!isset($json['access_token'], $json['expires_in'])) {
            throw new \RuntimeException('Could not get bol access token');
        }

        @mkdir(dirname($this->cacheFile), 0777, true);
        file_put_contents($this->cacheFile, json_encode([
            'access_token' => $json['access_token'],
            'expires_at' => time() + (int) $json['expires_in'],
        ]));

        return $json['access_token'];
    }
}
