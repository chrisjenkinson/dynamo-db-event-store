# Preparing a migration from Broadway

Broadway has reached end of life. This package intentionally remains Broadway-specific, but provides read-only tools for inventorying and verifying its existing DynamoDB event history before a migration to Patchlevel or another event-sourcing framework.

These tools **do not convert events**, write to a destination store, or change the existing event-store API, append logic, table schema or source events. No Patchlevel dependency is required.

## Preflight

1. Stop all event-store writers, scheduled commands and queue consumers, and wait for in-flight writes to finish. A DynamoDB strongly consistent scan is **not** a point-in-time snapshot if writes are ongoing.
2. Create a recoverable DynamoDB backup (for example, a point-in-time recovery backup/export).
3. Run a migration-readiness audit. Configure AWS credentials and region through the normal Async AWS environment or credential chain:

   ```bash
   php -m | grep pdo_sqlite
   php bin/audit-migration-readiness.php <source-table> [sqlite-working-directory]
   ```

   For DynamoDB Local, set `DYNAMODB_ENDPOINT` as well.

   Auditing uses an optional `pdo_sqlite` extension and a temporary on-disk SQLite file; it does not load all source identities into PHP memory. Ensure enough local disk is available. The file is removed when auditing completes or throws.

   The command prints JSON and exits with `0` if fully reconciled, `1` if discrepancies were found or `2` if the audit could not complete.

4. Investigate all discrepancies and repeat the audit after GSI propagation has caught up. Do not treat an incomplete or failed audit as a valid export.

The audit scans the base table **strongly consistently** and reads the legacy `Feed-GlobalPosition-index` GSI **eventually consistently**. It uses temporary SQLite records keyed by aggregate ID/playhead to verify that each source event appears exactly once in the GSI with the same serialized event fields and global position. It checks for missing replay entries, unmatched or duplicate replay entries, duplicate global positions, missing feed/position attributes, and aggregate playhead gaps.

Global-position gaps **by themselves are not errors**: the Broadway implementation allocates global positions before committing transactions, so failed appends can consume positions. The positions express **reservation order, not a guaranteed commit order**.

A successful audit proves reconciliation for the frozen records observed during that run, but cannot assert that writers were actually stopped, provide a snapshot across concurrent writes, or guarantee that event payload classes can be converted to the new framework.

### Example report

```json
{
  "ready": true,
  "source_events": 12345,
  "replay_events": 12345,
  "issues": {},
  "examples": {}
}
```

## Paginated raw export

Use `MigrationInventory` to read DynamoDB's original event fields without deserializing them into Broadway event objects. This is important when historical event PHP classes have been renamed or removed.

```php
use AsyncAws\DynamoDb\DynamoDbClient;
use chrisjenkinson\DynamoDbEventStore\MigrationInventory;

$inventory = new MigrationInventory(new DynamoDbClient(['region' => 'ap-southeast-2']), 'source-table');

$cursor = null;
do {
    $page = $inventory->sourcePage($cursor, 100);

    foreach ($page->events() as $event) {
        // Copy the raw payload, metadata and identifiers to a durable export.
        // $event->aggregateId, $event->playhead, $event->globalPosition,
        // $event->eventType, $event->recordedOn, $event->payloadClass,
        // $event->payloadPayload, $event->metadataClass, $event->metadataPayload
    }

    // Persist the opaque cursor ONLY AFTER the page's export is durably committed.
    $cursor = $page->nextCursor();
} while ($cursor !== null);
```

An inventory page can contain zero event records and still have a continuation cursor (the legacy counter is excluded). Always continue until the cursor is null.

A scan is not globally ordered. If the audit passes and the source is frozen, use `replayPage($cursor, $limit)` for events ordered by legacy global **reservation** position. Its cursor is separate from a source-scan cursor; do not interchange them.

Both readers expose raw payloads as stored strings. An invalid or malformed event row raises an exception instead of being skipped. An event lacking `Feed` or `GlobalPosition` remains visible in the authoritative scan, and the audit reports it.

## Cutover to Patchlevel

Conversion into Patchlevel message objects belongs in the migrating application, because only it knows the mapping from Broadway aggregate IDs, event names and metadata to Patchlevel aggregates and event types.

Use a **separate destination table** and deterministic source identifiers (aggregate ID + playhead) for idempotent, resumable imports. Preserve original timestamps, payloads and relevant metadata. Patchlevel should allocate new global indices; do not reuse Broadway's global positions as Patchlevel subscription checkpoints.

After importing, compare aggregate histories and counts, rebuild the necessary projections and subscription checkpoints, and only then point the application at the new store. Never restart normal customer notifications merely because historical events were replayed.

This package intentionally does not implement destination writes, Patchlevel serializers, mapping rules, dual-writes or a migration scheduler.
