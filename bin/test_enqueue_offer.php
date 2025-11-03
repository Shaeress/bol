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

// Enqueue test offer upsert batch request
$id = $q->enqueue('bol.request', [
    'eans' => ['5400977000013'],
    'action' => 'offer.upsert.batch',
    'prefix' => '/retailer'
]);

echo "Test offer upsert enqueued with ID: {$id}\n";
echo "EAN: 5400977000013\n";
echo "Action: offer.upsert.batch\n";
echo "Prefix: /retailer\n";
echo "\nYou can monitor the worker log and check queue status to see the processing.\n";