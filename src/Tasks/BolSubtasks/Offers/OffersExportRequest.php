<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offers;

use App\Bol\BolClient;
use App\Queue\Task;
use App\Queue\QueueInterface;
use Psr\Log\LoggerInterface;

final class OffersExportRequest
{
    public function __construct(
        private BolClient $bol,
        private QueueInterface $queue
    ) {
    }

    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $prefix = $task->payload['prefix'] ?? '/retailer';

        $log->info('Requesting offer export');
        $res = $this->bol->request(
            'POST',
            "{$prefix}/offers/export",
            ['prefix' => $prefix, 'json' => ['format' => 'CSV']],
        );
        $data = json_decode((string) $res->getBody(), true);
        $processId = $data['processStatusId'] ?? null;

        if (!$processId) {
            throw new \RuntimeException('No processStatusId returned');
        }

        $log->info('Offer export requested', ['processStatusId' => $processId]);

        $pollPrefix = str_replace('retailer','shared',$prefix);

        $this->queue->enqueue('bol.request', [
            'action' => 'offers.export.poll',
            'processStatusId' => $processId,
            'prefix' => $pollPrefix
        ]);
        
        return "Export request submitted with processStatusId: {$processId}";
    }
}
