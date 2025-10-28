<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks;

use App\DB\PdoFactory;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class ContentUpsertMarkSynced
{
    public function handle(Task $task, LoggerInterface $log): void
    {
        $ean = $task->payload['ean'] ?? null;
        if (!$ean) throw new \RuntimeException('Missing ean');

        $pdo = PdoFactory::make();
        $stmt = $pdo->prepare('UPDATE bol_content_sync SET last_status = "SUCCESS", synced_at = NOW() WHERE ean = ?');
        $stmt->execute([$ean]);

        $log->info('Content sync marked SUCCESS', ['ean' => $ean]);
    }
}
