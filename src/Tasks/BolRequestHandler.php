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
