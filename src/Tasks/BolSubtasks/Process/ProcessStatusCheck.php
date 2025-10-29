<?php
declare(strict_types=1);

namespace App\Tasks\BolSubtasks\Process;

use App\Bol\BolClient;
use App\Queue\QueueInterface;
use App\Queue\Task;
use App\DB\PdoFactory;
use Psr\Log\LoggerInterface;
use PDO;

final class ProcessStatusCheck
{
    public function __construct(private BolClient $bol, private QueueInterface $queue) {}

    public function handle(Task $task, LoggerInterface $log): ?string
    {
        $batchSize = $task->payload['batch_size'] ?? 100;
        $prefix = $task->payload['prefix'] ?? '/shared';
        
        $pdo = PdoFactory::make();
        
        // Calculate offset based on concurrent tasks to avoid overlapping data
        $offset = $task->concurrentCount * $batchSize;
        
        // Get pending processes that need to be checked
        $stmt = $pdo->prepare('
            SELECT process_id, ean, type, created_at, checked_at, status
            FROM bol_process_queue 
            WHERE status = "PENDING" 
            AND (checked_at IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 30 SECOND))
            ORDER BY created_at ASC 
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$batchSize, $offset]);
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($processes)) {
            $log->info('No pending processes found to check', [
                'offset' => $offset,
                'concurrent_count' => $task->concurrentCount
            ]);
            return 'No pending processes found';
        }
        
        $checkedCount = 0;
        $successCount = 0;
        $failedCount = 0;
        $stillPendingCount = 0;
        
        foreach ($processes as $process) {
            $processId = $process['process_id'];
            $ean = $process['ean'];
            $type = $process['type'];
            
            try {
                // Update checked_at timestamp
                $updateStmt = $pdo->prepare('
                    UPDATE bol_process_queue 
                    SET checked_at = NOW() 
                    WHERE process_id = ?
                ');
                $updateStmt->execute([$processId]);
                
                $log->debug('Checking BOL process status', [
                    'processId' => $processId,
                    'ean' => $ean,
                    'type' => $type,
                ]);

                $res = $this->bol->request('GET', "{$prefix}/process-status/{$processId}", [
                    'headers' => ['Accept' => 'application/vnd.retailer.v10+json'],
                ]);

                $data = json_decode((string)$res->getBody(), true);
                $status = $data['status'] ?? 'UNKNOWN';
                $lastResult = json_encode($data);

                // Check if it's a duplicate offer error (treat as success)
                $isDuplicateOffer = false;
                if ($status === 'FAILURE' && isset($data['errorMessage'])) {
                    $isDuplicateOffer = str_contains($data['errorMessage'], '[Duplicate Offer]');
                }

                if ($status === 'SUCCESS' || $isDuplicateOffer) {
                    if ($isDuplicateOffer) {
                        $log->info('Process failed with duplicate offer (treating as success)', [
                            'pid' => $processId, 
                            'ean' => $ean, 
                            'type' => $type,
                            'error' => $data['errorMessage']
                        ]);
                        
                        // For creation processes with duplicate offer error, extract the existing offerId
                        if ($type === 'create' && isset($data['errorMessage'])) {
                            if (preg_match('/offer \'([a-f0-9-]+)\'/i', $data['errorMessage'], $matches)) {
                                $offerId = $matches[1];
                                $mapUpdateStmt = $pdo->prepare('
                                    INSERT INTO bol_offer_map (ean, offer_id, last_synced_at)
                                    VALUES (?, ?, NOW())
                                    ON DUPLICATE KEY UPDATE 
                                        offer_id = VALUES(offer_id), 
                                        last_synced_at = NOW()
                                ');
                                $mapUpdateStmt->execute([$ean, $offerId]);
                                
                                $log->info('Offer mapping updated from duplicate error', [
                                    'ean' => $ean, 
                                    'offerId' => $offerId,
                                    'processId' => $processId
                                ]);
                            } else {
                                $log->warning('Duplicate offer error but could not extract offerId', [
                                    'ean' => $ean,
                                    'processId' => $processId,
                                    'errorMessage' => $data['errorMessage']
                                ]);
                            }
                        }
                    } else {
                        $log->info('Process succeeded', ['pid' => $processId, 'ean' => $ean, 'type' => $type]);
                        
                        // For successful creation processes, update the bol_offer_map with the returned offerId
                        if ($type === 'create' && isset($data['entityId']) && !empty($data['entityId'])) {
                            $offerId = $data['entityId'];
                            $mapUpdateStmt = $pdo->prepare('
                                INSERT INTO bol_offer_map (ean, offer_id, last_synced_at)
                                VALUES (?, ?, NOW())
                                ON DUPLICATE KEY UPDATE 
                                    offer_id = VALUES(offer_id), 
                                    last_synced_at = NOW()
                            ');
                            $mapUpdateStmt->execute([$ean, $offerId]);
                            
                            $log->info('Offer mapping updated from successful creation', [
                                'ean' => $ean, 
                                'offerId' => $offerId,
                                'processId' => $processId
                            ]);
                        }
                    }
                    
                    // Update process queue to success
                    $updateStmt = $pdo->prepare('
                        UPDATE bol_process_queue 
                        SET status = "SUCCESS", last_result = ?
                        WHERE process_id = ?
                    ');
                    $updateStmt->execute([$lastResult, $processId]);
                    
                    // Mark sync as successful in bol_content_sync table
                    $syncUpdateStmt = $pdo->prepare('
                        UPDATE bol_content_sync 
                        SET status = "success", last_synced_at = NOW()
                        WHERE ean = ?
                    ');
                    $syncUpdateStmt->execute([$ean]);
                    
                    $successCount++;
                    
                } elseif (in_array($status, ['FAILURE','ERROR','TIMEOUT','CANCELLED'], true)) {
                    $log->warning('Process failed', [
                        'pid' => $processId,
                        'status' => $status,
                        'ean' => $ean,
                        'type' => $type,
                        'error' => $data['errorMessage'] ?? 'No error message'
                    ]);
                    
                    // Update process queue to failed
                    $updateStmt = $pdo->prepare('
                        UPDATE bol_process_queue 
                        SET status = ?, last_result = ?
                        WHERE process_id = ?
                    ');
                    $updateStmt->execute([$status, $lastResult, $processId]);
                    
                    // Mark sync as error in bol_content_sync table
                    $syncUpdateStmt = $pdo->prepare('
                        UPDATE bol_content_sync 
                        SET status = "error", last_error = ?, last_synced_at = NOW()
                        WHERE ean = ?
                    ');
                    $errorMessage = $data['errorMessage'] ?? "Process failed with status: {$status}";
                    $syncUpdateStmt->execute([$errorMessage, $ean]);
                    
                    $failedCount++;
                    
                } else {
                    // Still pending - update last_result but keep status as PENDING
                    $updateStmt = $pdo->prepare('
                        UPDATE bol_process_queue 
                        SET last_result = ?
                        WHERE process_id = ?
                    ');
                    $updateStmt->execute([$lastResult, $processId]);
                    $stillPendingCount++;
                }
                
                $checkedCount++;
                
            } catch (\Throwable $e) {
                $log->error('Process check failed', [
                    'pid' => $processId,
                    'ean' => $ean,
                    'err' => $e->getMessage(),
                ]);
                
                // Update with error info
                $updateStmt = $pdo->prepare('
                    UPDATE bol_process_queue 
                    SET last_result = ?
                    WHERE process_id = ?
                ');
                $updateStmt->execute([json_encode(['error' => $e->getMessage()]), $processId]);
            }
        }
        
        // If there are still pending processes, schedule another check
        if ($stillPendingCount > 0) {
            $this->queue->enqueue('bol.request', [
                'action' => 'process.status.check',
                'batch_size' => $batchSize,
                'prefix' => $prefix,
            ], 60); // Check again in 60 seconds
        }
        
        $log->info('Batch process check completed', [
            'total_checked' => $checkedCount,
            'success' => $successCount,
            'failed' => $failedCount,
            'still_pending' => $stillPendingCount,
            'offset' => $offset,
            'concurrent_count' => $task->concurrentCount,
        ]);
        
        return "Checked {$checkedCount} processes: {$successCount} success, {$failedCount} failed, {$stillPendingCount} still pending (offset: {$offset})";
    }
}
