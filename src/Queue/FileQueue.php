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
        if (!$files) {
            return null;
        }
        // pick the oldest file
        usort($files, static fn($a, $b) => filemtime($a) <=> filemtime($b));
        $src = $files[0];
        $id = basename($src, '.json');
        $dst = "{$this->processing}/{$id}.json";

        // atomic move to lock
        if (!@rename($src, $dst)) {
            return null; // another worker took it
        }
        $data = json_decode((string)file_get_contents($dst), true, 512, JSON_THROW_ON_ERROR);
        
        // Increment attempts and update the processing file
        $data['attempts'] = ($data['attempts'] ?? 0) + 1;
        file_put_contents($dst, json_encode($data, JSON_THROW_ON_ERROR));
        
        return new Task($data['id'], $data['type'], $data['payload'], $data['attempts'], $data['createdAt'] ?? time());
    }

    public function ack(Task $task, ?string $info = null): void
    {
        $from = "{$this->processing}/{$task->id}.json";
        $to = "{$this->done}/{$task->id}.json";
        
        if ($info !== null) {
            // Read the processing file to get existing data and add info
            $data = json_decode((string)file_get_contents($from), true, 512, JSON_THROW_ON_ERROR);
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
            file_put_contents("{$this->pending}/{$task->id}.json", json_encode($data, JSON_THROW_ON_ERROR));
            @unlink($from);
        } else {
            file_put_contents("{$this->failed}/{$task->id}.json", json_encode($data, JSON_THROW_ON_ERROR));
            @unlink($from);
        }
    }
}
