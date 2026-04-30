# Migrating to GlobalPosition

This guide covers how to migrate an existing DynamoDB event store table to the schema introduced by the `GlobalPosition` feature.

## What Changed

Each event row now has two additional attributes:

- **`Feed`** (String) — always `'all'`; the hash key of the global replay index
- **`GlobalPosition`** (Number) — a monotonically increasing counter assigned atomically at append time

A Global Secondary Index (`Feed-GlobalPosition-index`) on `(Feed, GlobalPosition)` enables `visitEvents` to return events in the order they were committed.

A special counter item (`Id=_counter, Playhead=0`) is maintained in the same table to allocate `GlobalPosition` ranges atomically.

Existing rows without `Feed` and `GlobalPosition` will not appear in the GSI and will therefore be skipped by `visitEvents`.

---

## Before You Start

**Choose a long-running environment.** DynamoDB scans are paginated (at most 1 MB per call) and a large table will take many minutes of round trips. Run the script somewhere with no hard execution time limit:

- A CLI process on a server or developer machine
- An ECS task or EC2 instance (spin up for the migration, terminate after)
- An AWS Batch job

Do not use Lambda. Its maximum execution timeout is 15 minutes, which will not be sufficient for any non-trivial table.

**Sorting requires a two-pass approach.** To assign `GlobalPosition` values in `RecordedOn` order without loading all items into memory at once, the scan collects only the sort keys (RecordedOn + primary key) in the first pass, sorts them, then fetches or updates each item individually in sorted order. The sort keys are much smaller than full event items — roughly 70 bytes each — so a table with a million events needs around 70 MB for the key list.

For extremely large tables (tens of millions of events), write the sort keys to a file instead and use an external sort tool (e.g. Unix `sort`) before the second pass.

---

## Option A: Table Swap (recommended)

Stand up a new table with the new schema alongside the old one, replay all existing events through the new store, then cut over. No in-place modification of the original table is needed. Safe under concurrent writes because the old table remains read/write throughout.

### Steps

1. **Keep the old code running for writes** while you prepare the new table.

2. **Create the new table** with a temporary name:

   ```php
   $newEventStore = new DynamoDbEventStore($client, $inputBuilder, $normalizer, 'events-table-v2');
   $newEventStore->createTable();
   ```

3. **First pass — collect sort keys** from the old table, paginating until all pages are consumed:

   ```php
   $sortKeys         = [];
   $lastEvaluatedKey = null;

   do {
       $params = ['TableName' => 'events-table'];

       if (null !== $lastEvaluatedKey) {
           $params['ExclusiveStartKey'] = $lastEvaluatedKey;
       }

       $result = $client->scan($params);

       foreach ($result->getItems() as $item) {
           if (!isset($item['Metadata'])) {
               continue; // skip the _counter item if present
           }

           $sortKeys[] = [
               'RecordedOn' => $item['RecordedOn']->getS(),
               'Id'         => $item['Id']->getS(),
               'Playhead'   => (int) $item['Playhead']->getN(),
           ];
       }

       $lastEvaluatedKey = $result->getLastEvaluatedKey();
   } while ([] !== $lastEvaluatedKey);
   ```

4. **Sort keys by `RecordedOn`** to approximate the original append order. The tiebreaker on `Id` and `Playhead` makes the sort stable when timestamps collide, which is required for idempotency:

   ```php
   usort($sortKeys, function ($a, $b) {
       return $a['RecordedOn'] <=> $b['RecordedOn']
           ?: $a['Id'] <=> $b['Id']
           ?: $a['Playhead'] <=> $b['Playhead'];
   });
   ```

5. **Second pass — fetch items in batches of 100 using `BatchGetItem` and replay in position order.** `BatchGetItem` does not guarantee that items come back in request order, so fetched items are sorted back into their global position order before replaying. Catch `DuplicatePlayheadException` to skip events already written, making the script safe to re-run:

   ```php
   // Build a lookup from (Id#Playhead) → global position for re-sorting fetched batches.
   $positionMap = [];
   foreach ($sortKeys as $position => $key) {
       $positionMap[$key['Id'] . '#' . $key['Playhead']] = $position;
   }

   foreach (array_chunk($sortKeys, 100) as $chunk) {
       $keys = array_map(fn($key) => [
           'Id'       => new AttributeValue(['S' => $key['Id']]),
           'Playhead' => new AttributeValue(['N' => (string) $key['Playhead']]),
       ], $chunk);

       // BatchGetItem may return fewer than requested if DynamoDB throttles.
       // Retry unprocessed keys until all are fetched.
       $fetched   = [];
       $remaining = $keys;

       while ([] !== $remaining) {
           $result = $client->batchGetItem([
               'RequestItems' => [
                   'events-table' => ['Keys' => $remaining],
               ],
           ]);

           foreach ($result->getResponses()['events-table'] ?? [] as $item) {
               $fetched[] = $item;
           }

           // getUnprocessedKeys() returns array<string, KeysAndAttributes>; call getKeys() to get the array.
           $remaining = $result->getUnprocessedKeys()['events-table']?->getKeys() ?? [];
       }

       // Restore global position order before replaying.
       usort($fetched, function ($a, $b) use ($positionMap) {
           $posA = $positionMap[$a['Id']->getS() . '#' . $a['Playhead']->getN()];
           $posB = $positionMap[$b['Id']->getS() . '#' . $b['Playhead']->getN()];

           return $posA <=> $posB;
       });

       foreach ($fetched as $item) {
           $domainMessage = $normalizer->denormalize($item);

           try {
               $newEventStore->append(
                   $domainMessage->getId(),
                   new DomainEventStream([$domainMessage])
               );
           } catch (DuplicatePlayheadException) {
               continue; // already written on a previous run
           }
       }
   }
   ```

   Position assignments are deterministic — sorting by `RecordedOn` always produces the same order for the same data, so the same event receives the same `GlobalPosition` on every run. Skipping duplicates is safe.

6. **Verify the new table** — compare total item counts and spot-check a few aggregate streams.

7. **Cut over:** stop writes to the old table, update the table name in your application config to `events-table-v2`, redeploy.

8. **Delete the old table** once you are confident the migration is complete.

---

## Option B: In-Place Backfill

Modify the existing table without a full replay. Avoids a cutover window but requires writes to be paused for the duration of the backfill.

### Preconditions

- **Stop all writes** to the event store before starting. New appends after the new code is deployed but before the backfill completes will create the `_counter` item and write events with `GlobalPosition` values that conflict with the positions the backfill script is assigning.
- If stopping writes is not possible, use Option A instead.

### Steps

1. **Add the GSI to the existing table.** DynamoDB will backfill it automatically for any items that already have `Feed` (none at this point, so it starts empty):

   ```php
   $client->updateTable([
       'TableName'                  => 'events-table',
       'AttributeDefinitions'       => [
           ['AttributeName' => 'Feed',           'AttributeType' => 'S'],
           ['AttributeName' => 'GlobalPosition', 'AttributeType' => 'N'],
       ],
       'GlobalSecondaryIndexUpdates' => [[
           'Create' => [
               'IndexName' => 'Feed-GlobalPosition-index',
               'KeySchema' => [
                   ['AttributeName' => 'Feed',           'KeyType' => 'HASH'],
                   ['AttributeName' => 'GlobalPosition', 'KeyType' => 'RANGE'],
               ],
               'Projection' => ['ProjectionType' => 'ALL'],
           ],
       ]],
   ]);

   // tableExists() only checks the table status, not the GSI status.
   // Poll DescribeTable until the GSI itself is ACTIVE.
   do {
       sleep(5);
       $indexes = $client->describeTable(['TableName' => 'events-table'])
           ->getTable()
           ?->getGlobalSecondaryIndexes() ?? [];
       $gsiStatus = null;
       foreach ($indexes as $index) {
           if ('Feed-GlobalPosition-index' === $index->getIndexName()) {
               $gsiStatus = $index->getIndexStatus();
               break;
           }
       }
   } while ('ACTIVE' !== $gsiStatus);
   ```

2. **First pass — collect sort keys**, paginating through the full table:

   ```php
   $sortKeys         = [];
   $lastEvaluatedKey = null;

   do {
       $params = ['TableName' => 'events-table'];

       if (null !== $lastEvaluatedKey) {
           $params['ExclusiveStartKey'] = $lastEvaluatedKey;
       }

       $result = $client->scan($params);

       foreach ($result->getItems() as $item) {
           if (!isset($item['Metadata'])) {
               continue; // skip the _counter item if present
           }

           $sortKeys[] = [
               'RecordedOn' => $item['RecordedOn']->getS(),
               'Id'         => $item['Id']->getS(),
               'Playhead'   => (int) $item['Playhead']->getN(),
           ];
       }

       $lastEvaluatedKey = $result->getLastEvaluatedKey();
   } while ([] !== $lastEvaluatedKey);
   ```

3. **Sort keys by `RecordedOn`**. The tiebreaker on `Id` and `Playhead` makes the sort stable when timestamps collide, which is required for idempotency:

   ```php
   usort($sortKeys, function ($a, $b) {
       return $a['RecordedOn'] <=> $b['RecordedOn']
           ?: $a['Id'] <=> $b['Id']
           ?: $a['Playhead'] <=> $b['Playhead'];
   });
   ```

4. **Second pass — update each item** in sorted order to add `Feed` and `GlobalPosition`. Positions start at 1 to match the behaviour of `append()`, which uses `ADD` on a counter that DynamoDB initialises to 0, so the first increment returns 1. The condition `attribute_not_exists(GlobalPosition)` makes each write a no-op if the item was already updated, so the script is safe to re-run after an interruption:

   ```php
   foreach ($sortKeys as $index => $key) {
       $position = $index + 1; // 1-based, consistent with append()

       try {
           $client->updateItem([
               'TableName'                 => 'events-table',
               'Key'                       => [
                   'Id'       => new AttributeValue(['S' => $key['Id']]),
                   'Playhead' => new AttributeValue(['N' => (string) $key['Playhead']]),
               ],
               'ConditionExpression'       => 'attribute_not_exists(GlobalPosition)',
               'UpdateExpression'          => 'SET Feed = :feed, GlobalPosition = :gp',
               'ExpressionAttributeValues' => [
                   ':feed' => new AttributeValue(['S' => 'all']),
                   ':gp'   => new AttributeValue(['N' => (string) $position]),
               ],
           ]);
       } catch (ConditionalCheckFailedException) {
           continue; // already updated on a previous run
       }
   }
   ```

   Position assignments are deterministic — sorting by `RecordedOn` always produces the same order for the same data, so the same event receives the same `GlobalPosition` on every run.

5. **Create the `_counter` item** with `Value` set to the total number of events — the last position assigned. Use a condition so that re-running the script after a crash does not overwrite a counter that may already have been advanced by concurrent writes:

   ```php
   $lastPosition = count($sortKeys); // 1-based: last assigned position equals total count

   try {
       $client->putItem([
           'TableName'           => 'events-table',
           'ConditionExpression' => 'attribute_not_exists(Id)',
           'Item'                => [
               'Id'       => new AttributeValue(['S' => '_counter']),
               'Playhead' => new AttributeValue(['N' => '0']),
               'Value'    => new AttributeValue(['N' => (string) $lastPosition]),
           ],
       ]);
   } catch (ConditionalCheckFailedException) {
       // Counter already exists. This is expected on a re-run after a crash.
       // If writes were correctly stopped during the backfill, the existing value
       // is the one this script wrote on a previous run and is safe to leave as-is.
       // If writes were NOT stopped, investigate before resuming — the counter may
       // have been advanced by concurrent appends and overwriting it would cause
       // GlobalPosition collisions.
   }
   ```

6. **Resume writes.** New appends will call `ADD` on the counter, receiving the next available position.

---

## Limitations of Both Approaches

- **`RecordedOn` ordering is an approximation.** Broadway's `DateTime` stores microsecond precision (`Y-m-d\TH:i:s.uP`), so collisions are rare for sequential workloads. However, for concurrent appends — two processes calling `DateTime::now()` at the same time — timestamps can be identical or out-of-order relative to the actual DynamoDB commit sequence. The true interleaving at original append time is not stored anywhere in the table and cannot be recovered.

- **Option B is not safe under concurrent writes.** Use Option A if you cannot guarantee a write-free window during the backfill.
