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

		if (empty($brands) || empty($seasons)) {
			throw new \RuntimeException('Missing or empty brands or seasons list');
		}

		$pdo = PdoFactory::make();

		// Prepare the placeholders for IN clauses
		$brandPlaceholders = implode(',', array_fill(0, count($brands), '?'));
		$seasonPlaceholders = implode(',', array_fill(0, count($seasons), '?'));

		$sql = "
			SELECT s.ean, s.brand, s.season
			FROM bol_stg_offers AS s
			LEFT JOIN bol_content_sync AS cs ON cs.ean = s.ean
			WHERE s.brand IN ($brandPlaceholders)
			  AND s.season IN ($seasonPlaceholders)
			  AND (cs.status IS NULL OR cs.status IN ('pending','error'))
			ORDER BY
			  (cs.last_synced_at IS NOT NULL) ASC,
			  cs.last_synced_at ASC,
			  cs.retry_count ASC,
			  s.ean ASC
			LIMIT 3
		";

		$stmt = $pdo->prepare($sql);
		$stmt->execute([...$brands, ...$seasons]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (!$rows) {
			$log->info('No offers found to sync', ['brands' => $brands, 'seasons' => $seasons]);
			return;
		}

		foreach ($rows as $row) {
			$ean = $row['ean'];
			$brandId = $row['brand'];
			$seasonId = $row['season'];

			// mark as in progress
			$pdo->prepare("
                INSERT INTO bol_content_sync (ean, brand_id, season_id, status, last_synced_at)
                VALUES (?, ?, ?, 'in_progress', NOW())
                ON DUPLICATE KEY UPDATE
                    status='in_progress',
                    retry_count = retry_count + 1,
                    last_synced_at = NOW()
            ")->execute([$ean, $brandId, $seasonId]);

			// enqueue the upsert
			$this->queue->enqueue('bol.request', [
				'action' => 'offer.upsert',
				'ean' => $ean,
				'prefix' => $prefix,
			]);
		}

		$log->info('Queued offers for sync', [
			'count' => count($rows),
			'brands' => $brands,
			'seasons' => $seasons,
		]);
	}
}
