<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks;

use App\Bol\BolClient;
use App\DB\PdoFactory;
use Psr\Log\LoggerInterface;
use PDO;

final class BolProcessStatusWorker
{
    public function __construct(private BolClient $bol) {}

    public function handle(LoggerInterface $log): void
    {
        $pdo = PdoFactory::make();
        $st = $pdo->query("
            SELECT process_id, ean, type
            FROM bol_process_queue
            WHERE status = 'PENDING'
            ORDER BY created_at ASC
            LIMIT 100
        ");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $pid = $r['process_id'];
            try {
                $res = $this->bol->request('GET', "/shared/process-status/{$pid}", [
                    'headers' => ['Accept' => 'application/vnd.retailer.v10+json'],
                ]);
                $data = json_decode((string)$res->getBody(), true);
                $status = $data['status'] ?? 'UNKNOWN';

                $pdo->prepare("
                    UPDATE bol_process_queue
                    SET status = ?, last_result = ?, checked_at = NOW()
                    WHERE process_id = ?
                ")->execute([$status, json_encode($data), $pid]);

                if ($status === 'SUCCESS') {
                    $log->info('Process succeeded', ['pid' => $pid, 'ean' => $r['ean'], 'type' => $r['type']]);
                    // optionally trigger downstream success handling
                } elseif (in_array($status, ['FAILURE','ERROR','TIMEOUT'], true)) {
                    $log->warning('Process failed', ['pid' => $pid, 'ean' => $r['ean'], 'type' => $r['type']]);
                    // mark SyncTracker error etc.
                }

            } catch (\Throwable $e) {
                $log->error('Process check failed', ['pid' => $pid, 'err' => $e->getMessage()]);
            }
        }
    }
}
