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

// pick an endpoint you can call with your role, for example process status list or orders
$id = $q->enqueue('bol.request', [
    'action'      => 'process.status.check',
]);

echo "Enqueued bol.request {$id}\n";
