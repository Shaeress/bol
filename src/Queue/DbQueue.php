<?php
declare(strict_types=1);

namespace App\Queue;

use PDO;

final class DbQueue implements QueueInterface
{
	public function __construct(private PDO $pdo)
	{
	}

	public function enqueue(string $type, array $payload): string
	{
		$id = bin2hex(random_bytes(8));
		$stmt = $this->pdo->prepare(
			'INSERT INTO queue_tasks (id, type, payload, status) VALUES (?, ?, CAST(? AS JSON), "pending")'
		);
		$stmt->execute([$id, $type, json_encode($payload, JSON_THROW_ON_ERROR)]);
		return $id;
	}

	public function reserve(): ?Task
	{
		// claim one pending item atomically
		$token = bin2hex(random_bytes(8));
		$this->pdo->beginTransaction();

		$upd = $this->pdo->prepare(
			'UPDATE queue_tasks q
			 JOIN (
			     SELECT id
			     FROM queue_tasks
			     WHERE status = "pending" AND available_at <= NOW()
			     ORDER BY created_at ASC
			     LIMIT 1
			 ) pick ON pick.id = q.id
			 SET q.status = "processing",
			     q.reserved_at = NOW(),
			     q.worker_token = ?,
			     q.attempts = q.attempts + 1'
		);
		$upd->execute([$token]);

		if ($upd->rowCount() === 0) {
			$this->pdo->commit();
			return null;
		}

		$sel = $this->pdo->prepare(
			'SELECT id, type, payload, attempts, UNIX_TIMESTAMP(created_at) AS created_ts
     		FROM queue_tasks
     		WHERE worker_token = ? AND status = "processing"
     		LIMIT 1'
		);
		$sel->execute([$token]);
		$row = $sel->fetch();
		$this->pdo->commit();

		if (!$row) {
			return null;
		}

		$payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
		return new Task($row['id'], $row['type'], $payload, (int) $row['attempts'], (int) $row['created_ts']);
	}

	public function ack(Task $task, ?string $info = null): void
	{
		$stmt = $this->pdo->prepare('UPDATE queue_tasks SET status = "done", info = ? WHERE id = ?');
		$stmt->execute([$info, $task->id]);
	}

	public function nack(Task $task, string $reason, bool $requeue = false): void
	{
		$attempts = $task->attempts;
		$max = (int) ($_ENV['QUEUE_MAX_ATTEMPTS'] ?? 5);

		if ($attempts >= $max) {
			$stmt = $this->pdo->prepare(
				'UPDATE queue_tasks
             SET status = "failed",
                 reserved_at = NULL,
                 worker_token = NULL,
                 error = ?
             WHERE id = ?'
			);
			$stmt->execute([$reason, $task->id]);
			return;
		}

		$base = max(1, (int) ($_ENV['QUEUE_BACKOFF_BASE'] ?? 5));
		$cap = max($base, (int) ($_ENV['QUEUE_BACKOFF_CAP'] ?? 300));
		$delay = (int) min($cap, $base * (2 ** max(0, $attempts - 1)));

		$stmt = $this->pdo->prepare(
			'UPDATE queue_tasks
         SET status = "pending",
             reserved_at = NULL,
             worker_token = NULL,
             available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
             error = ?
         WHERE id = ?'
		);
		$stmt->execute([$delay, $reason, $task->id]);
	}

}
