# BOL Offer Mapping Fix

## Problem

The `bol_offer_map` table was not being populated correctly because:

1. **BOL API behavior**: When creating an offer, the `entityId` (offer ID) is only returned after polling the process status, not immediately from the creation request
2. **Missing logic**: The `ProcessStatusCheck` task was checking process statuses but not updating the `bol_offer_map` table with successful creation results
3. **Incomplete mapping**: This left many offers without proper `offer_id` mappings, causing issues with subsequent updates

## Solution

Enhanced the `ProcessStatusCheck` task to automatically populate `bol_offer_map` when creation processes complete:

### For Successful Creation Processes

When a creation process (`type = 'create'`) completes with `status = 'SUCCESS'`:

```php
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
}
```

### For Duplicate Offer Errors

When a creation process fails with `[Duplicate Offer]` error (treated as success):

```php
if ($type === 'create' && isset($data['errorMessage'])) {
    if (preg_match('/offer \'([a-f0-9-]+)\'/i', $data['errorMessage'], $matches)) {
        $offerId = $matches[1];
        // Same INSERT ... ON DUPLICATE KEY UPDATE logic
    }
}
```

## Key Features

1. **Automatic mapping**: No manual intervention needed - mappings are created automatically during process polling
2. **Handles both scenarios**: Works for both successful creations and duplicate offer errors
3. **Idempotent**: Uses `INSERT ... ON DUPLICATE KEY UPDATE` to safely handle existing mappings
4. **Comprehensive logging**: Logs all mapping updates for debugging
5. **Backward compatible**: Doesn't break existing functionality

## Files Modified

- `src/Tasks/BolSubtasks/Process/ProcessStatusCheck.php` - Added offer mapping logic

## Database Impact

The `bol_offer_map` table will now be properly populated with:
- `ean`: The product EAN code
- `offer_id`: The BOL offer ID (entityId from successful creation)
- `last_synced_at`: Timestamp of when the mapping was created/updated

## Benefits

1. **Complete mappings**: All successful offer creations will have proper `offer_id` mappings
2. **Better updates**: Subsequent offer updates can properly reference existing offers
3. **Reduced API calls**: No need for additional API calls to find offer IDs
4. **Improved reliability**: System works correctly even with BOL's asynchronous creation process

## Testing

Run the test script to check the current state:

```bash
php bin/test_offer_mapping.php
```

This will show:
- Current offer mappings
- Recent creation processes
- Any missing mappings that should be fixed
- Overall statistics

## Process Flow

```
1. OfferUpsertBatch creates offer → BOL returns processStatusId
2. Process gets queued in bol_process_queue with type='create'
3. ProcessStatusCheck polls the process status
4. When status='SUCCESS' → Extract entityId and update bol_offer_map
5. Future updates can now use the stored offer_id
```

This ensures the `bol_offer_map` table is correctly utilized and populated automatically!