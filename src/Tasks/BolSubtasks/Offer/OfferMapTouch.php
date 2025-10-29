<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offer;

use App\DB\PdoFactory;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class OfferMapTouch
{
    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $ean = $task->payload['ean'] ?? null;
        if (!$ean) throw new \RuntimeException('Missing ean');
        $fields = [];
        $vals = [];
        if (isset($task->payload['price'])) { $fields[] = 'last_price = ?'; $vals[] = (float)$task->payload['price']; }
        if (isset($task->payload['stock'])) { $fields[] = 'last_stock = ?'; $vals[] = (int)$task->payload['stock']; }
        $vals[] = $ean;
        $sql = 'UPDATE bol_offer_map SET ' . implode(', ', $fields) . ', last_synced_at = NOW() WHERE ean = ?';
        $pdo = PdoFactory::make();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        
        $affectedRows = $stmt->rowCount();
        return "Updated {$affectedRows} offer mapping(s) for EAN: {$ean}";
    }
}
