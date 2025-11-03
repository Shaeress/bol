<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\Bol\BolClient;
use App\DB\PdoFactory;
use App\Mappers\OfferMapper;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;
use PDO;

final class OfferUpsertBatch
{
    public function __construct(private BolClient $bol, private QueueInterface $queue)
    {
    }

    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $eans = $task->payload['eans'] ?? [];
        $prefix = $task->payload['prefix'] ?? '/retailer';
        if (empty($eans))
            throw new \RuntimeException('Missing eans');

        $pdo = PdoFactory::make();
        $processedCount = count($eans);
        $createsCount = 0;
        $updatesCount = 0;

        foreach ($eans as $ean) {
            try {
                $result = $this->upsertOne($pdo, $ean, $prefix, $log);
                if ($result === 'create') {
                    $createsCount++;
                } elseif ($result === 'update') {
                    $updatesCount++;
                }
                \App\Support\SyncTracker::markSuccess($ean);
            } catch (\Throwable $e) {
                \App\Support\SyncTracker::markError($ean, $e->getMessage());
                $log->error('Upsert failed', ['ean' => $ean, 'err' => $e->getMessage()]);
            }
        }

        // Only enqueue process status check if operations actually happened
        $statusCheckBatchSize = 0;
        if ($createsCount > 0) {
            $statusCheckBatchSize = 1; // Always 1 for creates
        } elseif ($updatesCount > 0) {
            $statusCheckBatchSize = $updatesCount; // Equal to number of updates
        }

        if ($statusCheckBatchSize > 0) {
            $this->queue->enqueue('bol.request', [
                'action' => 'process.status.check',
                'batch_size' => $statusCheckBatchSize,
                'prefix' => str_replace('retailer', 'shared', $prefix),
            ], 120); // 2 minutes delay

            $log->info('Enqueued process status check', [
                'batch_size' => $statusCheckBatchSize,
                'creates' => $createsCount,
                'updates' => $updatesCount,
                'delay_seconds' => 120
            ]);

            return "Processed {$processedCount} EANs ({$createsCount} creates, {$updatesCount} updates) and enqueued process status check (batch size: {$statusCheckBatchSize})";
        } else {
            $log->info('No operations performed, skipping process status check', [
                'processed_count' => $processedCount
            ]);

            return "Processed {$processedCount} EANs but no operations performed, no process status check needed";
        }
    }

    private function upsertOne(PDO $pdo, string $ean, string $prefix, LoggerInterface $log): ?string
    {
        // stage row
        $row = $this->fetchRow($pdo, $ean);
        if (!$row)
            throw new \RuntimeException('No staged offer for EAN ' . $ean);

        // map
        $offerPayload = OfferMapper::fromRow($row);

        // find mapping
        $map = $this->fetchMap($pdo, $ean);

        // ensure fulfilment has required "method"
        if (!isset($offerPayload['fulfilment']['method'])) {
            $offerPayload['fulfilment']['method'] = 'FBR';
        }

        if (!$map || !$map['offer_id']) {
            try {
                // create and wait inline
                $createResponse = $this->callCreate($prefix, $offerPayload, $ean, $pdo, $log);
                $pid = $createResponse['processStatusId'] ?? null;
                if (!$pid) {
                    $responseDebug = json_encode($createResponse);
                    throw new \RuntimeException("No processStatusId returned on create for {$ean}. Response: {$responseDebug}");
                }
                $this->queueProcessId($pdo, $pid, $ean, 'create');

                // Store mapping immediately with NULL offer_id (will be updated when process completes)
                $this->storeMap($pdo, $offerPayload, null, $row);
                $map = $this->fetchMap($pdo, $ean); // reload
                
                // Return 'create' to indicate a create operation was performed
                return 'create';
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                // Detect duplicate-offer error
                if (str_contains($msg, 'Duplicate found: retailer offer')) {
                    $log->info('Offer already exists, switching to update', ['ean' => $ean]);
                    if (preg_match('/offer \'([a-f0-9-]+)\'/i', $msg, $m)) {
                        $offerId = $m[1];
                        $this->storeMap($pdo, $offerPayload, $offerId, $row);
                        $map = $this->fetchMap($pdo, $ean);
                    } else {
                        $log->warning('Duplicate offer found but could not extract offerId', ['ean' => $ean]);
                        return null;
                    }
                } else {
                    throw $e;
                }
            }
        }

        $offerId = $map['offer_id'] ?? null;
        if (!$offerId) {
            $log->info('Offer mapping stored but offerId not yet available (awaiting process completion)', ['ean' => $ean]);
            return null;
        }

        $anyUpdated = false;

        $wantHash = OfferMapper::formCoreHash($offerPayload);

        if ($wantHash !== ($map['last_core_hash'] ?? null)) {
            $core = [
                'onHoldByRetailer' => (bool) ($offerPayload['onHoldByRetailer'] ?? false),
                'fulfilment' => [
                    'method' => 'FBR',
                    'deliveryCode' => $offerPayload['fulfilment']['deliveryCode'] ?? '1-2 weken',
                ],
            ];
            $pid = $this->callCoreUpdate($prefix, $offerId, $core, $ean);
            $this->queueProcessId($pdo, $pid, $ean, 'core');
            $this->touchCoreHash($pdo, $ean, $core, $wantHash);
            $anyUpdated = true;
        }

        // price if changed
        $newPrice = (float) $row['price'];
        if ($map['last_price'] === null || (float) $map['last_price'] !== $newPrice) {
            $pid = $this->callPriceUpdate($prefix, $offerId, $newPrice, $ean);
            $this->queueProcessId($pdo, $pid, $ean, 'price');
            $this->touchPrice($pdo, $ean, $newPrice);
            $anyUpdated = true;
        }

        // stock if changed - use stock already calculated by OfferMapper
        $newStock = $offerPayload['stock']['amount'];

        if ($map['last_stock'] === null || (int) $map['last_stock'] !== $newStock) {
            $pid = $this->callStockUpdate($prefix, $offerId, $newStock, $ean);
            $this->queueProcessId($pdo, $pid, $ean, 'stock');
            $this->touchStock($pdo, $ean, $newStock);
            $anyUpdated = true;
        }

        // If no updates were made, still update last_checked_at
        if (!$anyUpdated) {
            $this->setLastCheckedAt($pdo, $ean);
            return null; // No operations performed
        }
        
        return 'update'; // Updates were performed
    }

    private function fetchRow(PDO $pdo, string $ean): ?array
    {
        $st = $pdo->prepare('SELECT * FROM bol_stg_offers WHERE ean = ? LIMIT 1');
        $st->execute([$ean]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchMap(PDO $pdo, string $ean): ?array
    {
        $st = $pdo->prepare('SELECT offer_id, last_price, last_stock, last_core_hash FROM bol_offer_map WHERE ean = ?');
        $st->execute([$ean]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function storeMap(PDO $pdo, array $offerPayload, ?string $offerId, array $row): void
    {
        $ean = $offerPayload['ean'];

        // Calculate core hash using centralized function
        $coreHash = OfferMapper::formCoreHash($offerPayload);

        // Extract values from offerPayload
        $price = $offerPayload['pricing']['bundlePrices'][0]['unitPrice'];
        $stock = $offerPayload['stock']['amount'];
        $onHoldByRetailer = (int) ($offerPayload['onHoldByRetailer'] ?? false);
        $deliveryCode = $offerPayload['fulfilment']['deliveryCode'] ?? '1-2 weken';

        // Get season and brand_id from original row data
        $season = $row['season'] ?? null;
        $brandId = $row['brand_id'] ?? null;

        $st = $pdo->prepare("
            INSERT INTO bol_offer_map (
                ean, 
                offer_id, 
                season,
                brand_id,
                last_price,
                last_stock,
                on_hold_by_retailer,
                fulfilment_delivery_code,
                last_core_hash,
                last_synced_at,
                last_checked_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                offer_id = VALUES(offer_id),
                season = VALUES(season),
                brand_id = VALUES(brand_id),
                last_price = VALUES(last_price),
                last_stock = VALUES(last_stock),
                on_hold_by_retailer = VALUES(on_hold_by_retailer),
                fulfilment_delivery_code = VALUES(fulfilment_delivery_code),
                last_core_hash = VALUES(last_core_hash),
                last_synced_at = NOW(),
                last_checked_at = NOW()
        ");
        $st->execute([
            $ean,
            $offerId,
            $season,
            $brandId,
            $price,
            $stock,
            $onHoldByRetailer,
            $deliveryCode,
            $coreHash
        ]);
    }

    private function touchCoreHash(PDO $pdo, string $ean, array $core, string $hash): void
    {
        $st = $pdo->prepare("
            UPDATE bol_offer_map
            SET on_hold_by_retailer = ?,
                fulfilment_delivery_code = ?,
                last_core_hash = ?,
                last_synced_at = NOW(),
                last_checked_at = NOW()
            WHERE ean = ?
        ");
        $st->execute([
            (int) $core['onHoldByRetailer'],
            $core['fulfilment']['deliveryCode'],
            $hash,
            $ean,
        ]);
    }

    private function touchPrice(PDO $pdo, string $ean, float $price): void
    {
        $st = $pdo->prepare("
            UPDATE bol_offer_map
            SET last_price = ?, 
                last_synced_at = NOW(),
                last_checked_at = NOW()
            WHERE ean = ?
        ");
        $st->execute([$price, $ean]);
    }

    private function touchStock(PDO $pdo, string $ean, int $stock): void
    {
        $st = $pdo->prepare("
            UPDATE bol_offer_map
            SET last_stock = ?, 
                last_synced_at = NOW(),
                last_checked_at = NOW()
            WHERE ean = ?
        ");
        $st->execute([$stock, $ean]);
    }

    private function setLastCheckedAt(PDO $pdo, string $ean): void
    {
        $st = $pdo->prepare("
            UPDATE bol_offer_map
            SET last_checked_at = NOW()
            WHERE ean = ?
        ");
        $st->execute([$ean]);
    }

    private function callCreate(string $prefix, array $payload, string $ean, PDO $pdo, LoggerInterface $log): array
    {
        try {
            $res = $this->bol->request('POST', "{$prefix}/offers", [
                'headers' => [
                    'Accept' => 'application/vnd.retailer.v10+json',
                    'Content-Type' => 'application/vnd.retailer.v10+json',
                ],
                'json' => $payload,
            ]);
            $data = json_decode((string) $res->getBody(), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid JSON response from BOL API');
            }
            return $data;
        } catch (\RuntimeException $e) {
            // Pass through to duplicate-offer handler above
            throw $e;
        }
    }

    private function callCoreUpdate(string $prefix, string $offerId, array $core, string $ean): string
    {
        $body = [
            'reference' => $ean,
            'onHoldByRetailer' => $core['onHoldByRetailer'],
            'fulfilment' => [
                'method' => $core['fulfilment']['method'] ?? 'FBR',
                'deliveryCode' => $core['fulfilment']['deliveryCode'],
            ],
        ];
        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}", [
            'headers' => [
                'Accept' => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => $body,
        ]);
        $data = json_decode((string) $res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid)
            throw new \RuntimeException('No processStatusId on core update');
        return (string) $pid;
    }

    private function callPriceUpdate(string $prefix, string $offerId, float $price, string $ean): string
    {
        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}/price", [
            'headers' => [
                'Accept' => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => [
                'pricing' => [
                    'bundlePrices' => [
                        [
                            'quantity' => 1,
                            'unitPrice' => $price
                        ]
                    ]
                ],
            ],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid)
            throw new \RuntimeException('No processStatusId on price update');
        return (string) $pid;
    }

    private function callStockUpdate(string $prefix, string $offerId, int $stock, string $ean): string
    {
        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}/stock", [
            'headers' => [
                'Accept' => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => [
                'amount' => $stock,
                'managedByRetailer' => true,
            ],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid)
            throw new \RuntimeException('No processStatusId on stock update');
        return (string) $pid;
    }

    private function queueProcessId(PDO $pdo, string $processId, string $ean, string $type): void
    {
        $st = $pdo->prepare("
        INSERT INTO bol_process_queue (process_id, ean, type, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE checked_at = NULL, status = 'PENDING'
    ");
        $st->execute([$processId, $ean, $type]);
    }

    private function poll(string $prefix, string $processId, string $ean): array
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $res = $this->bol->request('GET', str_replace('retailer', 'shared', $prefix) . "/process-status/{$processId}", [
                'headers' => ['Accept' => 'application/vnd.retailer.v10+json'],
            ]);
            $data = json_decode((string) $res->getBody(), true);
            $status = $data['status'] ?? 'UNKNOWN';
            if ($status === 'SUCCESS')
                return $data;
            if (in_array($status, ['FAILURE', 'TIMEOUT', 'CANCELLED', 'ERROR', 'UNKNOWN'], true)) {
                $detail = $data['errorDescription'] ?? $data['errorMessage'] ?? "Process {$processId} failed";
                throw new \RuntimeException($detail);
            }
            // simple backoff
            usleep(500000); // 0.5s
            if ($attempts % 10 === 0) {
                // be polite
                usleep(1500000); // 1.5s
            }
        }
    }
}