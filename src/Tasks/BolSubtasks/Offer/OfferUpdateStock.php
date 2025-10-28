<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\Bol\BolClient;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class OfferUpdateStock
{
    public function __construct(
        private BolClient $bol,
        private QueueInterface $queue
    ) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $offerId = $task->payload['offerId'] ?? null;
        $amount  = $task->payload['amount'] ?? null;
        $managed = $task->payload['managedByRetailer'] ?? true;
        if (!$offerId || $amount === null) throw new \RuntimeException('Missing offerId or amount');
        $prefix = $task->payload['prefix'] ?? '/retailer';

        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}/stock", [
            'headers' => [
                'Accept'       => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => [
                'amount' => (int)$amount,
                'managedByRetailer' => (bool)$managed,
            ],
        ]);

        $data = json_decode((string)$res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid) throw new \RuntimeException('No processStatusId for stock update');

        $this->queue->enqueue('bol.request', [
            'action' => 'process.poll',
            'processStatusId' => $pid,
            'onSuccess' => $task->payload['onSuccess'] ?? null,
            'prefix' => $prefix,
        ]);
    }
}
