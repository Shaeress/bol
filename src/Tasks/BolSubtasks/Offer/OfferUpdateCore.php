<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\Bol\BolClient;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class OfferUpdateCore
{
    public function __construct(
        private BolClient $bol,
        private QueueInterface $queue
    ) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $offerId = $task->payload['offerId'] ?? null;
        $body    = $task->payload['data'] ?? null;
        if (!$offerId || !$body) throw new \RuntimeException('Missing offerId or body');
        $prefix = $task->payload['prefix'] ?? '/retailer';

        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}", [
            'headers' => [
                'Accept'       => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => $body,
        ]);

        $data = json_decode((string)$res->getBody(), true);
        $processId = $data['processStatusId'] ?? null;
        if (!$processId) throw new \RuntimeException('No processStatusId for offer update');

        $this->queue->enqueue('bol.request', [
            'action' => 'process.poll',
            'processStatusId' => $processId,
            'onSuccess' => $task->payload['onSuccess'] ?? null,
            'prefix' => $prefix,
        ]);
    }
}
