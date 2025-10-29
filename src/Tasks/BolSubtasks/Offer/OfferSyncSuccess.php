<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\DB\PdoFactory;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class OfferSyncSuccess
{
    public function handle(Task $task, LoggerInterface $log): void
    {
        $ean = $task->payload['ean'] ?? null;
        if (!$ean) return;
        $pdo = PdoFactory::make();
        $pdo->prepare("
            UPDATE bol_content_sync
            SET status='success', last_error=NULL, retry_count=0, last_synced_at=NOW()
            WHERE ean=?
        ")->execute([$ean]);
        $log->info('Offer sync success', ['ean' => $ean]);
    }
}
