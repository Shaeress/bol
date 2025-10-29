#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use App\Config\Config;
use App\Queue\DbQueue;
use App\Queue\FileQueue;
use App\DB\PdoFactory;

if (file_exists(__DIR__ . '/../.env')) {
    (new Dotenv())->load(__DIR__ . '/../.env');
}

$driver = strtolower(Config::get('QUEUE_DRIVER', 'file'));
$q = $driver === 'db' ? new DbQueue(PdoFactory::make())
                      : new FileQueue(Config::get('QUEUE_DIR', __DIR__ . '/../var/queue'));

// Get PDO connection for cleanup operations (only works with DB driver)
$pdo = $driver === 'db' ? PdoFactory::make() : null;

echo "Starting repeating enqueue loop (Ctrl+C to stop)...\n";

$iteration = 0;
while (true) {
    $iteration++;
    
    try {
        $timestamp = date('Y-m-d H:i:s');
        
        // Feature 1: Clean up stale processing tasks (only for DB driver)
        if ($pdo) {
            $cleanupStmt = $pdo->prepare("
                UPDATE queue_tasks 
                SET status = 'pending', reserved_at = NULL, worker_token = NULL
                WHERE status = 'processing' 
                AND reserved_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ");
            $cleanupStmt->execute();
            $cleanedUp = $cleanupStmt->rowCount();
            
            if ($cleanedUp > 0) {
                echo "[{$timestamp}] Cleaned up {$cleanedUp} stale processing tasks\n";
            }
        }
        
        // Feature 2: Check pending task count before enqueueing
        $pendingCount = 0;
        if ($pdo) {
            // For DB driver - count pending tasks
            $countStmt = $pdo->query("SELECT COUNT(*) FROM queue_tasks WHERE status = 'pending'");
            $pendingCount = (int)$countStmt->fetchColumn();
        } else {
            // For File driver - count pending files
            $pendingDir = Config::get('QUEUE_DIR', __DIR__ . '/../var/queue') . '/pending';
            if (is_dir($pendingDir)) {
                $files = glob($pendingDir . '/*.json');
                $pendingCount = count($files);
            }
        }
        
        echo "[{$timestamp}] Iteration {$iteration}: {$pendingCount} pending tasks\n";
        
        // Only enqueue if 3 or fewer items remain
        if ($pendingCount <= 3) {
            $id = $q->enqueue('bol.request', [
                'action' => 'offer.sync.batch'
            ]);
            
            echo "[{$timestamp}] Enqueued bol.request {$id}\n";
        } else {
            echo "[{$timestamp}] Skipped enqueue - too many pending tasks ({$pendingCount})\n";
        }
        
        // Wait 30 seconds before next iteration
        sleep(30);
        
    } catch (\Throwable $e) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] Iteration {$iteration}: ERROR - {$e->getMessage()}\n";
        
        // Wait a bit longer on error before retrying
        sleep(60);
    }
}