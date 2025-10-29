<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks;

use App\Bol\BolClient;
use App\Queue\Task;
use App\Queue\QueueInterface;
use Psr\Log\LoggerInterface;

final class ProcessPoll
{
	public function __construct(
		private BolClient $bol,
		private QueueInterface $queue
	) {
	}

	public function handle(Task $task, LoggerInterface $log): void
	{
		$id = $task->payload['processStatusId'] ?? null;
		if (!$id)
			throw new \RuntimeException('Missing processStatusId');

		$prefix = $task->payload['prefix'] ?? '/shared';
		$prefix = str_replace('retailer', 'shared', $prefix);

		$res = $this->bol->request('GET', "{$prefix}/process-status/{$id}", [
			'headers' => ['Accept' => 'application/vnd.retailer.v10+json'],
		]);

		$data = json_decode((string) $res->getBody(), true);
		$status = $data['status'] ?? 'UNKNOWN';
		$log->info('Process status', ['id' => $id, 'status' => $status]);

		if ($status === 'SUCCESS') {
			$onSuccess = $task->payload['onSuccess'] ?? null;
			if ($onSuccess) {
				if (!empty($data['entityId'])) {
					$onSuccess['offerId'] = $data['entityId'];
				}

				if (!isset($onSuccess['ean']) && isset($task->payload['ean'])) {
					$onSuccess['ean'] = $task->payload['ean'];
				}

				$this->queue->enqueue('bol.request', $onSuccess);
			}
			return;
		}

		if ($status === 'PENDING' || $status === 'IN_PROGRESS') {
			sleep(2);
			$this->queue->enqueue('bol.request', $task->payload);
			return;
		}

		if (!empty($task->payload['onFailure'])) {
			$this->queue->enqueue('bol.request', $task->payload['onFailure'] + ['status' => $status]);
		}

		// If this process failed and relates to an offer, mark sync as error
		if (in_array($status, ['FAILURE', 'TIMEOUT', 'CANCELLED', 'ERROR', 'UNKNOWN'], true)) {
			if (isset($task->payload['ean'])) {
				$detail = $data['errorDescription'] ?? $data['errorMessage'] ?? 'Process failed with status ' . $status;
				\App\Support\SyncTracker::markError($task->payload['ean'], $detail);
			}
			$log->warning('Process failed', [
				'id' => $id,
				'status' => $status,
				'ean' => $task->payload['ean'] ?? null,
			]);
		}
	}
}
