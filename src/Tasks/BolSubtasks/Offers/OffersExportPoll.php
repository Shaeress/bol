<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offers;

use App\Bol\BolClient;
use App\Queue\Task;
use App\Queue\QueueInterface;
use Psr\Log\LoggerInterface;

final class OffersExportPoll
{
    public function __construct(
        private BolClient $bol,
        private QueueInterface $queue
    ) {}

    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $processId = $task->payload['processStatusId'] ?? null;
        if (!$processId) {
            throw new \RuntimeException('Missing processStatusId');
        }

        $prefix = $task->payload['prefix'] ?? '/retailer';

        $options = [
            'headers' => [
                'Accept' => 'application/vnd.retailer.v10+json',
            ],
        ];

        $res = $this->bol->request('GET', "{$prefix}/process-status/{$processId}", $options);
        $data = json_decode((string)$res->getBody(), true);
        $status = $data['status'] ?? 'UNKNOWN';

        $log->info('Offer export status', ['id' => $processId, 'status' => $status]);

        if ($status === 'SUCCESS') {
            $reportId = $data['entityId'] ?? null;
            if (!$reportId) {
                throw new \RuntimeException('Missing reportId on SUCCESS');
            }
            $fetchPrefix = str_replace('shared', 'retailer', $prefix);
            $this->queue->enqueue('bol.request', [
                'action' => 'offers.export.fetch',
                'reportId' => $reportId,
                'prefix' => $fetchPrefix,
            ]);
            return "Export completed successfully, queued fetch for reportId: {$reportId}";
        }

        if ($status === 'PENDING' || $status === 'IN_PROGRESS') {
            sleep(2);
            $this->queue->enqueue('bol.request', [
                'action' => 'offers.export.poll',
                'processStatusId' => $processId,
                'prefix' => $prefix,
            ]);
            return "Export still in progress, re-queued poll";
        }

        $log->warning('Export ended in non success state', ['id' => $processId, 'status' => $status]);
        return "Export failed with status: {$status}";
    }
}
