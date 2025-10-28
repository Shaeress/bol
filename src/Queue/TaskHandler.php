<?php
declare(strict_types=1);

namespace App\Queue;

use Psr\Log\LoggerInterface;

interface TaskHandler
{
    public function handle(Task $task, LoggerInterface $log): ?string;
}
