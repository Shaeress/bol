<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Offers;

use App\Bol\BolClient;
use App\Queue\Task;
use Psr\Log\LoggerInterface;

final class OffersExportFetch
{
    public function __construct(private BolClient $bol) {}

    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $reportId = $task->payload['reportId'] ?? null;
        if (!$reportId) {
            throw new \RuntimeException('Missing reportId');
        }

        $prefix = $task->payload['prefix'] ?? '/retailer';      
        
        $options = [
            'headers' => [
                'Accept' => 'application/vnd.retailer.v10+csv',
            ],
        ];

        $res = $this->bol->request('GET', "{$prefix}/offers/export/{$reportId}", $options);
        $csv = (string)$res->getBody();

        $dir = __DIR__ . '/../../../../var/export/offers';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $path = "{$dir}/offers_{$reportId}.csv";
        file_put_contents($path, $csv);

        $log->info('Offer export saved', ['file' => $path]);
        
        $fileSize = strlen($csv);
        $lineCount = substr_count($csv, "\n");
        return "Export file saved to: {$path} (Size: {$fileSize} bytes, Lines: {$lineCount})";
    }
}
