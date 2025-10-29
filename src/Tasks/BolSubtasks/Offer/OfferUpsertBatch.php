<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\Bol\BolClient;
use App\DB\PdoFactory;
use App\Mappers\OfferMapper;
use App\Queue\Task;
use Psr\Log\LoggerInterface;
use PDO;

final class OfferUpsertBatch
{
    public function __construct(private BolClient $bol) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $eans   = $task->payload['eans']   ?? [];
        $prefix = $task->payload['prefix'] ?? '/retailer';
        if (empty($eans)) throw new \RuntimeException('Missing eans');

        $pdo = PdoFactory::make();

        foreach ($eans as $ean) {
            try {
                $this->upsertOne($pdo, $ean, $prefix, $log);
                \App\Support\SyncTracker::markSuccess($ean);
            } catch (\Throwable $e) {
                \App\Support\SyncTracker::markError($ean, $e->getMessage());
                $log->error('Upsert failed', ['ean' => $ean, 'err' => $e->getMessage()]);
                // continue with next EAN
            }
        }
    }

    private function upsertOne(PDO $pdo, string $ean, string $prefix, LoggerInterface $log): void
    {
        // stage row
        $row = $this->fetchRow($pdo, $ean);
        if (!$row) throw new \RuntimeException('No staged offer for EAN ' . $ean);

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
                $pid = $this->callCreate($prefix, $offerPayload, $ean, $pdo, $log);
                $this->queueProcessId($pdo, $pid, $ean, 'create');
                $offerId = $res['entityId'] ?? null;
                if (!$offerId) throw new \RuntimeException('No offerId returned on create for ' . $ean);
                $this->storeMap($pdo, $ean, $offerId);
                $map = $this->fetchMap($pdo, $ean); // reload
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                // Detect duplicate-offer error
                if (str_contains($msg, 'Duplicate found: retailer offer')) {
                    $log->info('Offer already exists, switching to update', ['ean' => $ean]);
                    if (preg_match('/offer \'([a-f0-9-]+)\'/i', $msg, $m)) {
                        $offerId = $m[1];
                        $this->storeMap($pdo, $ean, $offerId);
                        $map = $this->fetchMap($pdo, $ean);
                    } else {
                        $log->warning('Duplicate offer found but could not extract offerId', ['ean' => $ean]);
                        return;
                    }
                } else {
                    throw $e;
                }
            }
        }

        $offerId = $map['offer_id'] ?? null;
        if (!$offerId) {
            $log->warning('No offerId found after create/duplicate handling', ['ean' => $ean]);
            return;
        }

        // core update only if needed
        $wantCore = [
            'onHoldByRetailer' => (bool)($offerPayload['onHoldByRetailer'] ?? false),
            'fulfilment'       => [
                'method' => 'FBR',
                'deliveryCode' => $offerPayload['fulfilment']['deliveryCode'] ?? '1-2 weken',
            ],
        ];
        $wantHash = hash('sha256', json_encode($wantCore));

        if ($wantHash !== ($map['last_core_hash'] ?? null)) {
            $pid = $this->callCoreUpdate($prefix, $offerId, $wantCore, $ean);
            $this->queueProcessId($pdo, $pid, $ean, 'core');
            $this->updateCoreHash($pdo, $ean, $wantCore, $wantHash);
        }

        // price if changed
        $newPrice = (float)$row['price'];
        if ($map['last_price'] === null || (float)$map['last_price'] !== $newPrice) {
            $pid = $this->callPriceUpdate($prefix, $offerId, $newPrice, $ean);
            $this->queueProcessId($pdo, $pid, $ean, 'price');
            $this->touchPrice($pdo, $ean, $newPrice);
        }

        // stock if changed
        $newStock = max(0, (int)$row['stock']);
        if ($map['last_stock'] === null || (int)$map['last_stock'] !== $newStock) {
            $pid = $this->callStockUpdate($prefix, $offerId, $newStock, $ean);
            $this->queueProcessId($pdo, $pid, $ean, 'stock');
            $this->touchStock($pdo, $ean, $newStock);
        }
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

    private function storeMap(PDO $pdo, string $ean, string $offerId): void
    {
        $st = $pdo->prepare("
            INSERT INTO bol_offer_map (ean, offer_id, last_synced_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE offer_id = VALUES(offer_id), last_synced_at = NOW()
        ");
        $st->execute([$ean, $offerId]);
    }

    private function updateCoreHash(PDO $pdo, string $ean, array $core, string $hash): void
    {
        $st = $pdo->prepare("
            UPDATE bol_offer_map
            SET on_hold_by_retailer = ?,
                fulfilment_delivery_code = ?,
                last_core_hash = ?,
                last_synced_at = NOW()
            WHERE ean = ?
        ");
        $st->execute([
            (int)$core['onHoldByRetailer'],
            $core['fulfilment']['deliveryCode'],
            $hash,
            $ean,
        ]);
    }

    private function touchPrice(PDO $pdo, string $ean, float $price): void
    {
        $st = $pdo->prepare("
            UPDATE bol_offer_map
            SET last_price = ?, last_synced_at = NOW()
            WHERE ean = ?
        ");
        $st->execute([$price, $ean]);
    }

    private function touchStock(PDO $pdo, string $ean, int $stock): void
    {
        $st = $pdo->prepare("
            UPDATE bol_offer_map
            SET last_stock = ?, last_synced_at = NOW()
            WHERE ean = ?
        ");
        $st->execute([$stock, $ean]);
    }

    private function callCreate(string $prefix, array $payload, string $ean, PDO $pdo, LoggerInterface $log): string
    {
        try {
            $res = $this->bol->request('POST', "{$prefix}/offers", [
                'headers' => [
                    'Accept'       => 'application/vnd.retailer.v10+json',
                    'Content-Type' => 'application/vnd.retailer.v10+json',
                ],
                'json' => $payload,
            ]);
            $data = json_decode((string)$res->getBody(), true);
            $pid = $data['processStatusId'] ?? null;
            if (!$pid) throw new \RuntimeException('No processStatusId on create');
            return (string)$pid;
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
                'Accept'       => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => $body,
        ]);
        $data = json_decode((string)$res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid) throw new \RuntimeException('No processStatusId on core update');
        return (string)$pid;
    }

    private function callPriceUpdate(string $prefix, string $offerId, float $price, string $ean): string
    {
        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}/price", [
            'headers' => [
                'Accept'       => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => [
                'bundlePrices' => [['quantity' => 1, 'price' => $price]],
            ],
        ]);
        $data = json_decode((string)$res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid) throw new \RuntimeException('No processStatusId on price update');
        return (string)$pid;
    }

    private function callStockUpdate(string $prefix, string $offerId, int $stock, string $ean): string
    {
        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}/stock", [
            'headers' => [
                'Accept'       => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => [
                'amount' => $stock,
                'managedByRetailer' => true,
            ],
        ]);
        $data = json_decode((string)$res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid) throw new \RuntimeException('No processStatusId on stock update');
        return (string)$pid;
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
            $data = json_decode((string)$res->getBody(), true);
            $status = $data['status'] ?? 'UNKNOWN';
            if ($status === 'SUCCESS') return $data;
            if (in_array($status, ['FAILURE','TIMEOUT','CANCELLED','ERROR','UNKNOWN'], true)) {
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