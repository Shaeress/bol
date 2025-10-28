<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Bol\BolClient;
use App\Queue\Task;
use App\Queue\TaskHandler;
use App\Queue\QueueInterface;
use Psr\Log\LoggerInterface;

final class BolRequestHandler implements TaskHandler
{
    /** @var array<string, object> */
    private array $subtasks = [];

    public function __construct(
        private BolClient $bol,
        private QueueInterface $queue
    ) {
        // Register all Bol subtasks here
        $this->register('offers.export.request', new \App\Tasks\BolSubtasks\Offers\OffersExportRequest($this->bol, $this->queue));
        $this->register('offers.export.poll', new \App\Tasks\BolSubtasks\Offers\OffersExportPoll($this->bol, $this->queue));
        $this->register('offers.export.fetch', new \App\Tasks\BolSubtasks\Offers\OffersExportFetch($this->bol));
        $this->register('offer.create', new \App\Tasks\BolSubtasks\Offer\OfferCreateRequest($this->bol, $this->queue));
        $this->register('offer.create.store', new \App\Tasks\BolSubtasks\Offer\OfferCreateStore());
        $this->register('offer.update.core', new \App\Tasks\BolSubtasks\Offer\OfferUpdateCore($this->bol, $this->queue));
        $this->register('offer.update.price', new \App\Tasks\BolSubtasks\Offer\OfferUpdatePrice($this->bol, $this->queue));
        $this->register('offer.update.stock', new \App\Tasks\BolSubtasks\Offer\OfferUpdateStock($this->bol, $this->queue));
        $this->register('offer.upsert', new \App\Tasks\BolSubtasks\Offer\OfferUpsert($this->queue));
        $this->register('offer.map.touch', new \App\Tasks\BolSubtasks\Offer\OfferMapTouch());
    }

    public function register(string $name, object $handler): void
    {
        $this->subtasks[$name] = $handler;
    }

    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $action = $task->payload['action'] ?? null;
        if (!$action || !isset($this->subtasks[$action])) {
            throw new \RuntimeException("Unknown bol subtask: {$action}");
        }

        $log->info('BolRequestHandler dispatch', ['action' => $action]);
        return $this->subtasks[$action]->handle($task, $log);
    }
}
