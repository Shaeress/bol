<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Process;

use App\Bol\BolClient;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class ProcessStatusCheck
{
    public function __construct(private BolClient $bol, private QueueInterface $queue) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $processId = $task->payload['processId'] ?? null;
        $ean       = $task->payload['ean'] ?? null;
        $type      = $task->payload['type'] ?? 'unknown';
        $prefix    = $task->payload['prefix'] ?? '/shared';
        $attempt   = (int)($task->payload['attempt'] ?? 1);

        if (!$processId) {
            throw new \RuntimeException('Missing processId');
        }

        $log->debug('Checking BOL process status', [
            'processId' => $processId,
            'attempt'   => $attempt,
            'ean'       => $ean,
            'type'      => $type,
        ]);

        try {
            $res = $this->bol->request('GET', "{$prefix}/process-status/{$processId}", [
                'headers' => ['Accept' => 'application/vnd.retailer.v10+json'],
            ]);

            $data   = json_decode((string)$res->getBody(), true);
            $status = $data['status'] ?? 'UNKNOWN';

            if ($status === 'SUCCESS') {
                $log->info('Process succeeded', ['pid' => $processId, 'ean' => $ean, 'type' => $type]);
                // optional: mark in bol_offer_map or SyncTracker
                return;
            }

            if (in_array($status, ['FAILURE','ERROR','TIMEOUT','CANCELLED'], true)) {
                $log->warning('Process failed', [
                    'pid' => $processId,
                    'status' => $status,
                    'ean' => $ean,
                    'type' => $type,
                ]);
                // optional: SyncTracker::markError($ean, $status);
                return;
            }

            // Still pending -> requeue for later
            if ($attempt < 10) {
                $delaySeconds = min(60 * $attempt, 300); // grows up to 5 minutes max
                $log->debug('Requeueing process for later check', [
                    'pid' => $processId,
                    'delay' => $delaySeconds,
                ]);
                $this->queue->enqueue('bol.request', [
                    'action'    => 'process.status.check',
                    'processId' => $processId,
                    'ean'       => $ean,
                    'type'      => $type,
                    'prefix'    => $prefix,
                    'attempt'   => $attempt + 1,
                ], delay: $delaySeconds);
            } else {
                $log->warning('Process not resolved after 10 attempts', [
                    'pid' => $processId,
                    'ean' => $ean,
                ]);
            }

        } catch (\Throwable $e) {
            $log->error('Process check failed', [
                'pid' => $processId,
                'ean' => $ean,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
