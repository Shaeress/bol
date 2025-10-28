<?php
declare(strict_types=1);

namespace App\Queue;

interface QueueInterface
{
    public function enqueue(string $type, array $payload): string;
    public function reserve(): ?Task; // get next task and lock it
    public function ack(Task $task, ?string $info = null): void; // success
    public function nack(Task $task, string $reason, bool $requeue = false): void; // failure
}
