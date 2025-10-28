<?php
declare(strict_types=1);

namespace App\Queue;

final class Task
{
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public int $attempts = 0,
        public int $createdAt = 0
    ) {}
}
