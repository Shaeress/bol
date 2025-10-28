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
$log->pushHandler($handler);

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
$handler->setLevel($levelMap[strtolower(Config::get('LOG_LEVEL', 'info'))] ?? Logger::INFO);

$log->info('Worker booted', ['env' => Config::get('APP_ENV', 'dev')]);

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

$task = $queue->reserve();
if ($task === null) {
    $log->info('No tasks found');
    exit(0);
}

try {
    $log->info('Processing task', ['id' => $task->id, 'type' => $task->type]);
    $info = $router->dispatch($task, $log);
    $queue->ack($task, $info);
    $log->info('Task done', ['id' => $task->id, 'info' => $info]);
} catch (\Throwable $e) {
    $log->error('Task failed', ['id' => $task->id, 'err' => $e->getMessage()]);
    $queue->nack($task, $e->getMessage(), false);
    exit(1);
}