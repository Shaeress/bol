# Concurrent Task Counting System

This system prevents multiple batch processing tasks from working on the same data by using **concurrent counting** instead of blocking overlapping tasks entirely.

## How It Works

### 1. Task Reservation with Concurrent Counting

When a worker reserves a task, the queue system:

1. **Reserves the task normally** (no more blocking)
2. **Counts concurrent tasks** of the same type and action that are currently processing
3. **Passes this count** to the task via the `Task::$concurrentCount` property

### 2. Offset-Based Data Selection

Batch processing tasks use the concurrent count to calculate a **data offset**:

```php
$offset = $task->concurrentCount * $batchSize;
```

This ensures each concurrent task works on a different data slice:

- **Task 1** (concurrent count = 0): processes records 0-99
- **Task 2** (concurrent count = 1): processes records 100-199  
- **Task 3** (concurrent count = 2): processes records 200-299
- etc.

### 3. Supported Task Types

#### OfferSyncBatch (`offer.sync.batch`)
- **Uses offset**: YES
- **Formula**: `offset = concurrentCount × limit`
- **Purpose**: Prevents multiple batch syncs from processing the same EANs
- **Query**: `LIMIT {limit} OFFSET {offset}`

#### ProcessStatusCheck (`process.status.check`)
- **Uses offset**: YES  
- **Formula**: `offset = concurrentCount × batchSize`
- **Purpose**: Distributes process checking across multiple workers
- **Query**: `LIMIT {batchSize} OFFSET {offset}`

#### OfferUpsertBatch (`offer.upsert.batch`)
- **Uses offset**: NO
- **Reason**: This task has explicit EAN lists in the payload, so no overlap possible
- **Behavior**: Ignores `concurrentCount`

## Implementation Details

### Queue Changes

**Task Class** (`src/Queue/Task.php`):
```php
public function __construct(
    public string $id,
    public string $type,
    public array $payload,
    public int $attempts = 0,
    public int $createdAt = 0,
    public int $concurrentCount = 0  // NEW
) {}
```

**DbQueue** (`src/Queue/DbQueue.php`):
- Removed the NOT EXISTS clause that blocked overlapping tasks
- Added concurrent counting query after task reservation
- Counts tasks with same type and action that are currently processing

**FileQueue** (`src/Queue/FileQueue.php`):
- Replaced `hasOverlappingTask()` with `countConcurrentTasks()`
- Counts processing files with same type and action

### Task Handler Changes

**OfferSyncBatch**:
```php
$offset = $task->concurrentCount * $limit;
$sql = "SELECT ... LIMIT $limit OFFSET $offset";
```

**ProcessStatusCheck**:
```php
$offset = $task->concurrentCount * $batchSize;  
$sql = "SELECT ... LIMIT $batchSize OFFSET $offset";
```

## Benefits

1. **No More Blocking**: Multiple workers can process the same task type simultaneously
2. **Data Isolation**: Each worker processes different data sets automatically  
3. **Better Throughput**: More parallel processing without conflicts
4. **Automatic Load Distribution**: Workers naturally spread across available data
5. **Fault Tolerance**: If one worker fails, others continue with their data slices

## Example Scenario

With 3 workers and `offer.sync.batch` tasks (limit=100):

| Worker | Concurrent Count | Offset | Records Processed |
|--------|------------------|--------|-------------------|
| Worker 1 | 0 | 0 | EANs 1-100 |
| Worker 2 | 1 | 100 | EANs 101-200 |
| Worker 3 | 2 | 200 | EANs 201-300 |

Each worker processes completely different EANs, eliminating conflicts!

## Testing

Run the test script to see concurrent counting in action:

```bash
php bin/test_concurrent_counting.php
```

This will:
1. Enqueue multiple tasks of the same types
2. Reserve them to see the concurrent counts
3. Show expected offset calculations
4. Clean up the test tasks