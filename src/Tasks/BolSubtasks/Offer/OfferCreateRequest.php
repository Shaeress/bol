<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\Bol\BolClient;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class OfferCreateRequest
{
    public function __construct(
        private BolClient $bol,
        private QueueInterface $queue
    ) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $payload = $task->payload['data'] ?? null;
        if (!$payload) throw new \RuntimeException('Missing offer payload');
        $prefix = $task->payload['prefix'] ?? '/retailer';

        $log->info('Creating offer', ['ean' => $payload['ean'] ?? null]);

        $res = $this->bol->request('POST', "{$prefix}/offers", [
            'headers' => [
                'Accept'       => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => $payload,
        ]);

        $data = json_decode((string)$res->getBody(), true);
        $processId = $data['processStatusId'] ?? null;
        if (!$processId) throw new \RuntimeException('No processStatusId for offer create');

        $this->queue->enqueue('bol.request', [
            'action' => 'process.poll',
            'processStatusId' => $processId,
            'onSuccess' => [
                'action' => 'offer.create.store',
                'ean'    => $payload['ean'],
            ],
            'prefix' => $prefix,
        ]);
    }
}
