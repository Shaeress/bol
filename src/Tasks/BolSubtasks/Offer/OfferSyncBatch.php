<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\DB\PdoFactory;
use App\Queue\QueueInterface;
use App\Queue\Task;
use Psr\Log\LoggerInterface;
use PDO;

final class OfferSyncBatch
{
	public function __construct(private QueueInterface $queue)
	{
	}

	public function handle(Task $task, LoggerInterface $log): void
	{
		$brands = $task->payload['brands'] ?? ['002', '003', '004', '005', '274'];
		$seasons = $task->payload['seasons'] ?? ['251', '252', '999'];
		$prefix = $task->payload['prefix'] ?? '/retailer';
		$limit = max(1, (int) ($task->payload['limit'] ?? 100));

		if (empty($brands) || empty($seasons)) {
			throw new \RuntimeException('Missing or empty brands or seasons list');
		}

		$pdo = PdoFactory::make();

		// Prepare the placeholders for IN clauses
		$brandPlaceholders = implode(',', array_fill(0, count($brands), '?'));
		$seasonPlaceholders = implode(',', array_fill(0, count($seasons), '?'));

		$sql = "
			SELECT s.ean, s.brand_id, s.season
			FROM bol_stg_offers AS s
			LEFT JOIN bol_content_sync AS cs ON cs.ean = s.ean
			WHERE s.brand_id IN ($brandPlaceholders)
			  AND s.season IN ($seasonPlaceholders)
			  AND (cs.status IS NULL OR cs.status IN ('pending','error'))
			ORDER BY
			  (cs.last_synced_at IS NULL) DESC,
			  cs.last_synced_at ASC,
			  cs.retry_count ASC,
			  s.ean ASC
			LIMIT $limit
		";

		$stmt = $pdo->prepare($sql);
		$stmt->execute([...$brands, ...$seasons]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (!$rows) {
			$log->info('No offers found to sync', ['brands' => $brands, 'seasons' => $seasons]);
			return;
		}

		$eans = [];
		foreach ($rows as $r) {
			$eans[] = $r['ean'];
			\App\Support\SyncTracker::markInProgress($r['ean'], (int) $r['brand_id'], (int) $r['season']);
		}

		$this->queue->enqueue('bol.request', [
			'action' => 'offer.upsert.batch',
			'eans' => $eans,
			'prefix' => $prefix,
		]);

		$log->info('Queued upsert batch', ['count' => count($eans)]);
	}
}
