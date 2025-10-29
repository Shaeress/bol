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

if ($driver === 'db') {
    $queue = new DbQueue(PdoFactory::make());
    $pdo = PdoFactory::make();
    
    echo "Queue Status (Database Driver)\n";
    echo "==============================\n\n";
    
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM queue_tasks 
        GROUP BY status 
        ORDER BY status
    ");
    $results = $stmt->fetchAll();
    
    $total = 0;
    foreach ($results as $row) {
        echo sprintf("%-12s: %d\n", ucfirst($row['status']), $row['count']);
        $total += $row['count'];
    }
    echo sprintf("%-12s: %d\n", "TOTAL", $total);
    
    // Show recent tasks
    echo "\nRecent Tasks (last 10):\n";
    echo "=======================\n";
    $stmt = $pdo->query("
        SELECT id, type, status, created_at, attempts, 
               LEFT(JSON_EXTRACT(payload, '$.action'), 30) as action
        FROM queue_tasks 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $recent = $stmt->fetchAll();
    
    foreach ($recent as $task) {
        $action = trim($task['action'] ?? '', '"');
        echo sprintf("%s | %-15s | %-10s | %s | attempts: %d | %s\n", 
            $task['id'], 
            $task['type'], 
            $task['status'], 
            $task['created_at'],
            $task['attempts'],
            $action
        );
    }
    
    // BOL Process Queue status if exists
    try {
        $stmt = $pdo->query("
            SELECT status, COUNT(*) as count 
            FROM bol_process_queue 
            GROUP BY status 
            ORDER BY status
        ");
        $bolResults = $stmt->fetchAll();
        
        if ($bolResults) {
            echo "\nBOL Process Queue Status:\n";
            echo "=========================\n";
            foreach ($bolResults as $row) {
                echo sprintf("%-12s: %d\n", $row['status'], $row['count']);
            }
        }
    } catch (\Exception $e) {
        // Table might not exist
    }
    
} else {
    $queueDir = Config::get('QUEUE_DIR', __DIR__ . '/../var/queue');
    
    echo "Queue Status (File Driver)\n";
    echo "==========================\n\n";
    
    $dirs = ['pending', 'processing', 'done', 'failed'];
    $total = 0;
    
    foreach ($dirs as $dir) {
        $path = "{$queueDir}/{$dir}";
        $count = 0;
        if (is_dir($path)) {
            $files = glob("{$path}/*.json");
            $count = count($files);
        }
        echo sprintf("%-12s: %d\n", ucfirst($dir), $count);
        $total += $count;
    }
    echo sprintf("%-12s: %d\n", "TOTAL", $total);
    
    // Show recent tasks from pending
    echo "\nRecent Pending Tasks (last 10):\n";
    echo "===============================\n";
    $pendingPath = "{$queueDir}/pending";
    if (is_dir($pendingPath)) {
        $files = glob("{$pendingPath}/*.json");
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        
        foreach (array_slice($files, 0, 10) as $file) {
            $data = json_decode(file_get_contents($file), true);
            $action = $data['payload']['action'] ?? 'N/A';
            $created = date('Y-m-d H:i:s', $data['createdAt'] ?? filemtime($file));
            echo sprintf("%s | %-15s | %s | %s\n", 
                $data['id'] ?? basename($file, '.json'),
                $data['type'] ?? 'unknown',
                $created,
                $action
            );
        }
    }
}

echo "\nTimestamp: " . date('Y-m-d H:i:s') . "\n";