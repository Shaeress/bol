#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

use App\Config\Config;
use App\Queue\FileQueue;
use App\Queue\DbQueue;
use App\DB\PdoFactory;

if (file_exists(__DIR__ . '/../.env')) {
    (new Dotenv())->load(__DIR__ . '/../.env');
}

$log = new Logger('worker');
$handler = new StreamHandler(__DIR__ . '/../var/log/worker.log');
$format = "[%datetime%] %level_name% %channel%: %message% %context% %extra%\n";
$handler->setFormatter(new LineFormatter($format, null, true, true));

// set level from env
$levelMap = [
    'debug' => Logger::DEBUG,
    'info' => Logger::INFO,
    'notice' => Logger::NOTICE,
    'warning' => Logger::WARNING,
    'error' => Logger::ERROR,
    'critical' => Logger::CRITICAL,
    'alert' => Logger::ALERT,
    'emergency' => Logger::EMERGENCY,
];
$logLevel = $levelMap[strtolower(Config::get('LOG_LEVEL', 'info'))] ?? Logger::INFO;
$handler->setLevel($logLevel);
$log->pushHandler($handler);

// Only log startup message if level allows it
if ($logLevel <= Logger::INFO) {
    $log->info('Worker loop started', ['env' => Config::get('APP_ENV', 'dev')]);
}

use App\Queue\Router;
use App\Tasks\PingHandler;

$driver = strtolower(Config::get('QUEUE_DRIVER', 'file'));

if ($driver === 'db') {
    $queue = new DbQueue(PdoFactory::make());
    $log->info('Queue driver selected', ['driver' => 'db']);
} else {
    $queue = new FileQueue(Config::get('QUEUE_DIR', __DIR__ . '/../var/queue'));
    $log->info('Queue driver selected', ['driver' => 'file']);
}

$router = new Router();

// Ping
$router->register('ping', new PingHandler());

// Bol Request
use App\Bol\BolClient;
use App\Tasks\BolRequestHandler;

$bol = new BolClient(null, $log);
$router->register('bol.request', new BolRequestHandler($bol, $queue));

echo "Starting worker loop (Ctrl+C to stop)...\n";

$processed = 0;
$lastActivity = date('Y-m-d H:i:s');
$verboseConsole = $logLevel <= Logger::INFO; // Only show console output if log level allows INFO

while (true) {
    try {
        $task = $queue->reserve();
        
        if ($task === null) {
            // No tasks available, wait a bit
            if ($verboseConsole) {
                echo "[" . date('Y-m-d H:i:s') . "] No tasks found, waiting 5 seconds...\n";
            }
            sleep(5);
            continue;
        }
        
        $processed++;
        $timestamp = date('Y-m-d H:i:s');
        if ($verboseConsole) {
            echo "[{$timestamp}] Processing task #{$processed}: {$task->id} ({$task->type})\n";
        }
        
        $log->info('Processing task', [
            'id' => $task->id, 
            'type' => $task->type, 
            'iteration' => $processed
        ]);
        
        $info = $router->dispatch($task, $log);
        $queue->ack($task, $info);
        
        $log->info('Task done', ['id' => $task->id, 'info' => $info]);
        if ($verboseConsole) {
            echo "[{$timestamp}] Task {$task->id} completed successfully\n";
        }
        
        $lastActivity = $timestamp;
        
        // Small delay between tasks to prevent overwhelming the system
        sleep(1);
        
    } catch (\Throwable $e) {
        $timestamp = date('Y-m-d H:i:s');
        
        if (isset($task)) {
            $log->error('Task failed', ['id' => $task->id, 'err' => $e->getMessage()]);
            $queue->nack($task, $e->getMessage(), false);
            echo "[{$timestamp}] Task {$task->id} FAILED: {$e->getMessage()}\n"; // Always show errors
        } else {
            $log->error('Worker error', ['err' => $e->getMessage()]);
            echo "[{$timestamp}] Worker ERROR: {$e->getMessage()}\n"; // Always show errors
        }
        
        // Wait a bit longer on error before continuing
        sleep(5);
    }
    
    // Periodic status update every 50 tasks
    if ($processed > 0 && $processed % 50 === 0 && $verboseConsole) {
        echo "[" . date('Y-m-d H:i:s') . "] Status: Processed {$processed} tasks, last activity: {$lastActivity}\n";
    }
}