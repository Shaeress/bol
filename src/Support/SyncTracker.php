<?php
declare(strict_types=1);

namespace App\Support;

use App\DB\PdoFactory;
use PDO;

final class SyncTracker
{
    public static function markInProgress(string $ean, ?int $brandId = null, ?int $seasonId = null): void
    {
        $pdo = PdoFactory::make();
        $pdo->prepare("
            INSERT INTO bol_content_sync (ean, brand_id, season_id, status, last_synced_at)
            VALUES (?, ?, ?, 'in_progress', NOW())
            ON DUPLICATE KEY UPDATE
                status='in_progress',
                last_synced_at=NOW()
        ")->execute([$ean, $brandId, $seasonId]);
    }

    public static function markError(string $ean, string $error): void
    {
        $pdo = PdoFactory::make();
        $pdo->prepare("
            INSERT INTO bol_content_sync (ean, status, last_error, retry_count, last_synced_at)
            VALUES (?, 'error', ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                status='error',
                last_error=?,
                retry_count=retry_count+1,
                last_synced_at=NOW()
        ")->execute([$ean, $error, $error]);
    }

    public static function markSuccess(string $ean): void
    {
        $pdo = PdoFactory::make();
        $pdo->prepare("
            UPDATE bol_content_sync
            SET status='success', last_error=NULL, retry_count=0, last_synced_at=NOW()
            WHERE ean=?
        ")->execute([$ean]);
    }
}
