<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\Bol\BolClient;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class OfferUpdatePrice
{
    public function __construct(
        private BolClient $bol,
        private QueueInterface $queue
    ) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $offerId = $task->payload['offerId'] ?? null;
        $bundlePrices = $task->payload['bundlePrices'] ?? null; // array of {quantity, price}
        if (!$offerId || !$bundlePrices) throw new \RuntimeException('Missing offerId or bundlePrices');
        $prefix = $task->payload['prefix'] ?? '/retailer';

        $res = $this->bol->request('PUT', "{$prefix}/offers/{$offerId}/price", [
            'headers' => [
                'Accept'       => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ],
            'json' => ['bundlePrices' => $bundlePrices],
        ]);

        $data = json_decode((string)$res->getBody(), true);
        $pid = $data['processStatusId'] ?? null;
        if (!$pid) throw new \RuntimeException('No processStatusId for price update');

        $this->queue->enqueue('bol.request', [
            'action' => 'process.poll',
            'processStatusId' => $pid,
            'onSuccess' => $task->payload['onSuccess'] ?? null,
            'prefix' => $prefix,
        ]);
    }
}
