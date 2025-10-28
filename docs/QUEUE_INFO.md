# Queue Info Feature

The queue system now supports returning information from successful tasks. This allows task handlers to provide useful feedback about what was accomplished during task execution.

## Database Migration

If you have an existing `queue_tasks` table, run this migration:

```sql
ALTER TABLE queue_tasks 
ADD COLUMN info TEXT NULL COMMENT 'Success info from task handlers' 
AFTER error;
```

## How to Use

### Task Handlers

Task handlers can now return a string with information about what was completed:

```php
class MyTaskHandler implements TaskHandler
{
    public function handle(Task $task, LoggerInterface $log): ?string
    {
        // Do your work here...
        
        // Return info about what was accomplished
        return "Processed 150 items successfully";
        
        // Or return null if no specific info needed
        return null;
    }
}
```

### Queue Implementations

Both `DbQueue` and `FileQueue` now store the returned info:

- **Database**: Info is stored in the `info` column of completed tasks
- **File Queue**: Info is stored in the JSON file in the `done/` folder

### Worker

The worker automatically captures return values from handlers and stores them:

```php
try {
    $info = $router->dispatch($task, $log);
    $queue->ack($task, $info);  // Info is stored if provided
    $log->info('Task done', ['id' => $task->id, 'info' => $info]);
} catch (\Throwable $e) {
    // Error handling remains the same
    $queue->nack($task, $e->getMessage(), false);
}
```

## Examples

Here are some examples of useful info that handlers might return:

```php
// File processing
return "Processed file.csv: 1,250 rows, 15 errors, saved to output/results.json";

// API operations
return "Created order #12345, total: €99.99, customer: john@example.com";

// Export operations
return "Export completed: 500 products exported to offers_export_abc123.csv (45KB)";

// Polling operations
return "Poll completed: status SUCCESS, report ID: def456";
```

## Backward Compatibility

This change is fully backward compatible:

- Existing handlers that return void/null will continue to work
- Tasks that don't need to return info can return null
- The `ack()` method works with or without the info parameter