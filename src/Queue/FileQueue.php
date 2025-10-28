<?php
declare(strict_types=1);

namespace App\Queue;

final class FileQueue implements QueueInterface
{
    private string $base;
    private string $pending;
    private string $processing;
    private string $done;
    private string $failed;

    public function __construct(string $baseDir)
    {
        $this->base = rtrim($baseDir, DIRECTORY_SEPARATOR);
        $this->pending = "{$this->base}/pending";
        $this->processing = "{$this->base}/processing";
        $this->done = "{$this->base}/done";
        $this->failed = "{$this->base}/failed";
        foreach ([$this->pending, $this->processing, $this->done, $this->failed] as $d) {
            if (!is_dir($d)) {
                mkdir($d, 0777, true);
            }
        }
    }

    public function enqueue(string $type, array $payload): string
    {
        $id = bin2hex(random_bytes(8));
        $file = "{$this->pending}/{$id}.json";
        $data = [
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'attempts' => 0,
            'createdAt' => time(),
        ];
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
        return $id;
    }

    public function reserve(): ?Task
    {
        $files = glob($this->pending . '/*.json');
        if (!$files)
            return null;
        usort($files, static fn($a, $b) => filemtime($a) <=> filemtime($b));

        foreach ($files as $src) {
            $row = json_decode((string) file_get_contents($src), true);
            if (isset($row['nextAt']) && $row['nextAt'] > time()) {
                continue;
            }
            $id = basename($src, '.json');
            $dst = "{$this->processing}/{$id}.json";
            if (@rename($src, $dst)) {
                $d = json_decode((string) file_get_contents($dst), true);
                return new Task($d['id'], $d['type'], $d['payload'], (int) ($d['attempts'] ?? 0), (int) ($d['createdAt'] ?? time()));
            }
        }
        return null;
    }

    public function ack(Task $task, ?string $info = null): void
    {
        $from = "{$this->processing}/{$task->id}.json";
        $to = "{$this->done}/{$task->id}.json";

        if ($info !== null) {
            // Read the processing file to get existing data and add info
            $data = json_decode((string) file_get_contents($from), true, 512, JSON_THROW_ON_ERROR);
            $data['info'] = $info;
            $data['completedAt'] = time();
            file_put_contents($to, json_encode($data, JSON_THROW_ON_ERROR));
            @unlink($from);
        } else {
            @rename($from, $to);
        }
    }

    public function nack(Task $task, string $reason, bool $requeue = false): void
    {
        $from = "{$this->processing}/{$task->id}.json";
        $data = [
            'id' => $task->id,
            'type' => $task->type,
            'payload' => $task->payload,
            'attempts' => $task->attempts,
            'createdAt' => $task->createdAt,
            'error' => $reason,
            'failedAt' => time(),
        ];
        if ($requeue) {
            $nextAt = time();
            $base = (int) ($_ENV['QUEUE_BACKOFF_BASE'] ?? 5);
            $cap = (int) ($_ENV['QUEUE_BACKOFF_CAP'] ?? 300);
            $delay = (int) min($cap, $base * (2 ** max(0, $task->attempts - 1)));
            $nextAt += $delay;

            $data['nextAt'] = $nextAt;
            file_put_contents("{$this->pending}/{$task->id}.json", json_encode($data, JSON_THROW_ON_ERROR));
            @unlink($from);
        } else {
            file_put_contents("{$this->failed}/{$task->id}.json", json_encode($data, JSON_THROW_ON_ERROR));
            @unlink($from);
        }
    }
}
