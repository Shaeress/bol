<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\DB\PdoFactory;
use App\Mappers\OfferMapper;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;
use PDO;

final class OfferUpsert
{
    public function __construct(private QueueInterface $queue) {}

    public function handle(Task $task, LoggerInterface $log): void
    {
        $ean = $task->payload['ean'] ?? null;
        $prefix = $task->payload['prefix'] ?? '/retailer';
        if (!$ean) throw new \RuntimeException('Missing ean');

        try {
            $pdo = PdoFactory::make();

            // load staged row
            $stmt = $pdo->prepare('SELECT * FROM bol_stg_offers WHERE ean = ? LIMIT 1');
            $stmt->execute([$ean]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException('No staged offer for EAN ' . $ean);
            }

            // check map for existing offerId
            $m = $pdo->prepare('SELECT offer_id, last_price, last_stock FROM bol_offer_map WHERE ean = ?');
            $m->execute([$ean]);
            $map = $m->fetch(PDO::FETCH_ASSOC);

            $offerPayload = \App\Mappers\OfferMapper::fromRow($row);

            if (!$map || !$map['offer_id']) {
                // create
                $this->queue->enqueue('bol.request', [
                    'action' => 'offer.create',
                    'prefix' => $prefix,
                    'data'   => $offerPayload,
                    'ean'    => $ean, // propagate EAN downstream
                ]);

                // mark as success (we at least queued it properly)
                $this->queue->enqueue('bol.request', [
                    'action' => 'offer.sync.success',
                    'ean'    => $ean,
                ]);

                return;
            }

            $offerId = $map['offer_id'];

            // split updates: core, price, stock
            // core
            $core = [
                'reference' => $row['ean'],
                'onHoldByRetailer' => $offerPayload['onHoldByRetailer'],
                'fulfilment' => $offerPayload['fulfilment'] ?? ['deliveryCode' => '1-2 weken'],
            ];
            $this->queue->enqueue('bol.request', [
                'action'   => 'offer.update.core',
                'prefix'   => $prefix,
                'offerId'  => $offerId,
                'data'     => $core,
                'ean'      => $ean,
            ]);

            // price if changed
            $newPrice = (float)$row['price'];
            if ($map['last_price'] === null || (float)$map['last_price'] !== $newPrice) {
                $this->queue->enqueue('bol.request', [
                    'action'      => 'offer.update.price',
                    'prefix'      => $prefix,
                    'offerId'     => $offerId,
                    'bundlePrices'=> [['quantity' => 1, 'price' => $newPrice]],
                    'onSuccess'   => ['action' => 'offer.map.touch', 'ean' => $ean, 'price' => $newPrice],
                    'ean'         => $ean,
                ]);
            }

            // stock if changed
            $newStock = max(0, (int)$row['stock']);
            if ($map['last_stock'] === null || (int)$map['last_stock'] !== $newStock) {
                $this->queue->enqueue('bol.request', [
                    'action'    => 'offer.update.stock',
                    'prefix'    => $prefix,
                    'offerId'   => $offerId,
                    'amount'    => $newStock,
                    'onSuccess' => ['action' => 'offer.map.touch', 'ean' => $ean, 'stock' => $newStock],
                    'ean'       => $ean,
                ]);
            }

            // record local success after enqueueing all subtasks
            $this->queue->enqueue('bol.request', [
                'action' => 'offer.sync.success',
                'ean'    => $ean,
            ]);

        } catch (\Throwable $e) {
            $log->error('Offer upsert failed', [
                'ean'   => $ean,
                'error' => $e->getMessage(),
            ]);

            // record sync error
            $this->queue->enqueue('bol.request', [
                'action' => 'offer.sync.error',
                'ean'    => $ean,
                'error'  => $e->getMessage(),
            ]);

            // Optionally rethrow if you want retry logic to handle it
            // throw $e;
        }
    }
}
