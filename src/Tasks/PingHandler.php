<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Queue\Task;
use App\Queue\TaskHandler;
use Psr\Log\LoggerInterface;

final class PingHandler implements TaskHandler
{
    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $log->info('Ping handled', ['payload' => $task->payload]);
        return null;
    }
}
