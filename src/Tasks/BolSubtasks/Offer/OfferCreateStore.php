<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\DB\PdoFactory;
use App\Queue\Task;
use Psr\Log\LoggerInterface;
use PDO;

final class OfferCreateStore
{
    public function handle(Task $task, LoggerInterface $log): void
    {
        $ean = $task->payload['ean'] ?? null;
        if (!$ean) throw new \RuntimeException('Missing ean');

        // The generic poll already logged SUCCESS, fetch the last entityId from process result
        // If you persisted the process result, read from there. If not, pass entityId in payload.
        // For simplicity we expect poll to pass it forward if available.
        $offerId = $task->payload['offerId'] ?? null;
        if (!$offerId) {
            // defensive fail early so we do not create a bad map entry
            throw new \RuntimeException('Missing offerId on create store');
        }

        $pdo = PdoFactory::make();
        $stmt = $pdo->prepare(
            'INSERT INTO bol_offer_map (ean, offer_id, last_synced_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE offer_id = VALUES(offer_id), last_synced_at = NOW()'
        );
        $stmt->execute([$ean, $offerId]);

        $log->info('Offer mapped', ['ean' => $ean, 'offerId' => $offerId]);
    }
}
