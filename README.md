# DynamoDB Event Store

A PHP event store implementation for [Broadway](https://github.com/broadway/broadway) using Amazon DynamoDB as the persistence layer.

## Overview

This library provides a Broadway-compatible event store backed by AWS DynamoDB, allowing you to build event-sourced applications with DynamoDB as your event storage. It handles the serialization, normalization, and querying of domain events stored in DynamoDB.

## Features

- 🏗️ **Event Sourcing**: Full support for Broadway's event store interface
- 📊 **DynamoDB Integration**: Uses async-aws/dynamo-db for non-blocking DynamoDB operations
- 🔄 **Event Management**: Create, read, and manage event streams by aggregate ID
- 🎯 **Playhead Support**: Query events from a specific playhead (event version)
- ✅ **Type Safety**: Strict PHP typing throughout
- 🧪 **Well Tested**: Includes comprehensive unit tests with mutation testing

## Requirements

- PHP 8.1 or higher within PHP 8.x (`^8.1` in Composer)
- Broadway 2.4.0 or later
- Async AWS DynamoDB client (1.3.0+ or 3.0.0+)

## Installation

Install the package via Composer:

```bash
composer require chrisjenkinson/dynamo-db-event-store
```

## Quick Start

### Setup

First, create and configure the DynamoDB event store:

```php
use AsyncAws\DynamoDb\DynamoDbClient;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;
use Broadway\Serializer\SimpleInterfaceSerializer;

// Initialize DynamoDB client
$client = new DynamoDbClient([
    'region' => 'us-east-1',
]);

// Create the event store
$inputBuilder = new InputBuilder();
$normalizer = new DomainMessageNormalizer(
    new SimpleInterfaceSerializer(),
    new SimpleInterfaceSerializer(),
    new JsonEncoder(),
    new JsonDecoder()
);

$eventStore = new DynamoDbEventStore(
    $client,
    $inputBuilder,
    $normalizer,
    'events-table', // DynamoDB table name
    aggregateConsistentReads: false, // set true for strongly consistent aggregate reads
    replayPageSize: 1000 // batch size used internally by visitEvents()
);

// Create the table (if it doesn't exist)
$eventStore->createTable();
```

### Table Schema

The event store automatically creates a DynamoDB table with the following structure:

- **Partition Key**: `Id` (String) - The aggregate ID
- **Sort Key**: `Playhead` (Number) - The event version number
- **Billing Mode**: Pay-per-request

Additional attributes written to each event row:

- **Feed** (String) - Always `all`; used as the partition key of the global replay index
- **GlobalPosition** (Number) - Reserved atomically before the event batch is committed; failed writes can leave gaps

A Global Secondary Index (`Feed-GlobalPosition-index`) on `Feed` (HASH) + `GlobalPosition` (RANGE) enables ordered cross-aggregate replay.

### Usage

#### Append Events

```php
use Broadway\Domain\DomainEventStream;

$eventStream = new DomainEventStream([
    // your domain events
]);

$eventStore->append($aggregateId, $eventStream);
```

#### Load Events

```php
// Load all events for an aggregate
$eventStream = $eventStore->load($aggregateId);

// Load events from a specific playhead onwards
$eventStream = $eventStore->loadFromPlayhead($aggregateId, $playhead);
```

#### Visit Events

```php
use Broadway\EventStore\Management\Criteria;

$eventStore->visitEvents(new Criteria(), $eventVisitor);
```

Events are visited in `GlobalPosition` reservation order. A failed append may leave gaps, and reservation order does not guarantee transaction commit order.

#### Load Replay Pages

```php
use Broadway\EventStore\Management\Criteria;

$page = $eventStore->loadReplayPageAfterGlobalPosition(Criteria::create(), $checkpoint, 500);

foreach ($page->events() as $event) {
    $projector->apply($event->message());
}

$checkpoint = $page->lastProcessedGlobalPosition();
$hasMore    = $page->hasMore();
```

`$limit` is a bound on examined replay rows, not emitted events. When callers supply event-type criteria without aggregate-root criteria, we filter stored `Type` metadata before payload deserialization. We do not deserialize known nonmatching event types. We still fully deserialize matching events and propagate malformed-payload errors. The checkpoint is after every examined row, including pages with no emitted events; DynamoDB continuation state is available through `hasMore()`.

## Replay Ordering

Aggregate streams are ordered by `Playhead`. Cross-aggregate replay (`visitEvents`, `loadReplayPageAfterGlobalPosition`) uses `GlobalPosition`, assigned atomically per append batch and stored in a DynamoDB Global Secondary Index. Events are visited in global **reservation** order, which is not necessarily commit order. Failed appends may leave positions unused. This legacy GSI is eventually consistent; see [migration readiness](docs/migration-readiness.md) for authoritative verification before exporting history.

## Read Consistency

Aggregate stream reads (`load`, `loadFromPlayhead`) use eventually consistent reads by default. Pass `aggregateConsistentReads: true` to the constructor for strongly consistent aggregate reads:

```php
$eventStore = new DynamoDbEventStore($client, $inputBuilder, $normalizer, 'table', aggregateConsistentReads: true);
```

Global replay reads (`visitEvents`, `loadReplayPageAfterGlobalPosition`) are always eventually consistent — DynamoDB Global Secondary Indexes do not support strongly consistent reads.

`visitEvents()` drains replay pages using a constructor-configurable `replayPageSize`, which defaults to `1000`.

## Migrating away from Broadway

Use the read-only `MigrationInventory` and `MigrationReadinessAuditor` to inventory historical events and reconcile them against the eventually consistent replay index before migrating. The auditor uses an optional `pdo_sqlite` temporary file and does not change the production event store or table.

See [Migration readiness and raw export](docs/migration-readiness.md) for the required write freeze, the audit command, paginated cursors and migration caveats.

## Core Components

- **DynamoDbEventStore**: Main event store implementation
- **DomainMessageNormalizer**: Handles serialization/deserialization of domain events
- **InputBuilder**: Constructs DynamoDB query inputs
- **JsonEncoder/JsonDecoder**: JSON serialization utilities

## Testing

The project includes comprehensive tests:

```bash
# Run all tests
composer run tests

# Run specific test suites
composer run phpunit          # Unit tests
composer run phpstan          # Static analysis
composer run infection        # Mutation testing
```

### Continuous Integration

We run PHPUnit and PHPStan on PHP 8.1 through 8.5. We run mutation testing separately on PHP 8.3, with Xdebug and DynamoDB Local, and explicitly resolve Infection from the `^0.35.0` release series.

Infection remains a development dependency. We retain older version alternatives so contributors can install development dependencies on PHP 8.1 and 8.2. Consumers of this library do not install its development dependencies.

The mutation job runs `composer run-script infection -- --threads=1`. We use one worker because the integration tests recreate a shared DynamoDB table. We record the mutation results in the CI log without enforcing a minimum mutation score.

## Development

### Code Quality

This project maintains high code quality standards:

- **PHPUnit**: Unit testing
- **PHPStan**: Static type checking
- **Easy Coding Standard**: Code style enforcement
- **Infection**: Mutation testing for test coverage validation

### Scripts

```bash
# Run complete test suite
composer run tests

# Run individual checks
composer run phpunit
composer run phpstan
composer run infection
```

## Architecture

The event store follows Broadway's EventStore interface and includes event management capabilities. Events are serialized to JSON and stored in DynamoDB with metadata, allowing for complete event replay and reconstruction of aggregate state.

## License

This project is licensed under the GNU General Public License v3.0. See LICENSE file for details.

## Author

Chris Jenkinson

## Related Projects

- [Broadway](https://github.com/broadway/broadway) - Event sourcing framework for PHP
- [Async AWS SDK](https://github.com/async-aws/async-aws) - Non-blocking AWS SDK for PHP
