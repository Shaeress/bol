<?php
declare(strict_types=1);

namespace App\Queue;

use Psr\Log\LoggerInterface;

final class Router
{
    /** @var array<string, TaskHandler> */
    private array $handlers = [];

    public function register(string $type, TaskHandler $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function dispatch(Task $task, LoggerInterface $log): ?string
    {
        $handler = $this->handlers[$task->type] ?? null;
        if (!$handler) {
            throw new \RuntimeException("No handler for task type {$task->type}");
        }
        return $handler->handle($task, $log);
    }
}
