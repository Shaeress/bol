<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\DB\PdoFactory;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;
use PDO;

final class OfferSyncError
{
    private const MAX_RETRIES = 5;

    public function __construct(private ?QueueInterface $queue = null) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $ean = $task->payload['ean'] ?? null;
        $error = $task->payload['error'] ?? 'Unknown error';
        if (!$ean) return;

        $pdo = PdoFactory::make();

        // Record or update failure
        $pdo->prepare("
            INSERT INTO bol_content_sync (ean, status, last_error, retry_count, last_synced_at)
            VALUES (?, 'error', ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                status='error',
                last_error=?,
                retry_count=retry_count+1,
                last_synced_at=NOW()
        ")->execute([$ean, $error, $error]);

        // Fetch retry count to decide if we requeue
        $retry = (int)$pdo->query("SELECT retry_count FROM bol_content_sync WHERE ean = " . $pdo->quote($ean))->fetchColumn();

        if ($retry >= self::MAX_RETRIES) {
            $pdo->prepare("
                UPDATE bol_content_sync
                SET status='failed', last_error=CONCAT(:error, ' (max retries reached)'), last_synced_at=NOW()
                WHERE ean=:ean
            ")->execute(['ean' => $ean, 'error' => $error]);

            $log->warning('Offer permanently failed', ['ean' => $ean, 'retries' => $retry]);
            return;
        }

        // Optional: requeue if queue available
        if ($this->queue) {
            $this->queue->enqueue('bol.request', [
                'action' => 'offer.upsert',
                'ean' => $ean,
                'prefix' => '/retailer',
            ]);
            $log->info('Offer requeued for retry', ['ean' => $ean, 'attempt' => $retry]);
        } else {
            $log->warning('Offer not requeued (no queue available)', ['ean' => $ean]);
        }
    }
}
