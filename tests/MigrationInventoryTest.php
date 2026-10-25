<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\Input\ScanInput;
use AsyncAws\DynamoDb\Result\QueryOutput;
use AsyncAws\DynamoDb\Result\ScanOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use Broadway\Serializer\SimpleInterfaceSerializer;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;
use chrisjenkinson\DynamoDbEventStore\MigrationCursor;
use chrisjenkinson\DynamoDbEventStore\MigrationEvent;
use chrisjenkinson\DynamoDbEventStore\MigrationInventory;
use chrisjenkinson\DynamoDbEventStore\MigrationReadinessAuditor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MigrationInventoryTest extends TestCase
{
    private DynamoDbClient $client;
    private DynamoDbEventStore $store;
    private MigrationInventory $inventory;

    protected function setUp(): void
    {
        $this->client = new DynamoDbClient(Configuration::create([
            'endpoint' => (string) getenv('DYNAMODB_ENDPOINT'),
            'accessKeyId' => (string) getenv('DYNAMODB_ACCESS_KEY_ID'),
            'accessKeySecret' => (string) getenv('DYNAMODB_SECRET_ACCESS_KEY'),
        ]));

        $this->store = new DynamoDbEventStore(
            $this->client,
            new InputBuilder(),
            new DomainMessageNormalizer(new SimpleInterfaceSerializer(), new SimpleInterfaceSerializer(), new JsonEncoder(), new JsonDecoder()),
            'table'
        );
        $this->store->deleteTable();
        $this->store->createTable();

        $this->inventory = new MigrationInventory($this->client, 'table');
    }

    public function test_it_exports_raw_events_across_source_scan_pages(): void
    {
        $this->append('first', 0);
        $this->append('second', 0);

        $events = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->inventory->sourcePage($cursor, 1);
            $events = array_merge($events, $page->events());
            $cursor = $page->nextCursor();
            ++$pages;
        } while ($cursor !== null && $pages < 20);

        self::assertNull($cursor);
        self::assertCount(2, $events);
        self::assertGreaterThanOrEqual(3, $pages); // Includes the counter row.
        self::assertContainsOnlyInstancesOf(MigrationEvent::class, $events);

        $ids = array_map(static fn (MigrationEvent $event): string => $event->aggregateId, $events);
        sort($ids);
        self::assertSame(['first', 'second'], $ids);

        foreach ($events as $event) {
            self::assertNotNull($event->globalPosition);
            self::assertSame('all', $event->feed);
            self::assertSame(TestEvent::class, $event->payloadClass);
            self::assertSame('{"id":"' . $event->aggregateId . '"}', $event->payloadPayload);
        }
    }

    public function test_it_uses_strong_reads_for_source_and_eventual_reads_for_replay(): void
    {
        $capturedScan = null;
        $capturedQuery = null;

        $scanOutput = $this->createStub(ScanOutput::class);
        $scanOutput->method('getItems')->willReturn([]);
        $scanOutput->method('getLastEvaluatedKey')->willReturn([]);

        $queryOutput = $this->createStub(QueryOutput::class);
        $queryOutput->method('getItems')->willReturn([]);
        $queryOutput->method('getLastEvaluatedKey')->willReturn([]);

        $client = $this->createStub(DynamoDbClient::class);
        $client->method('scan')->willReturnCallback(static function (ScanInput $input) use (&$capturedScan, $scanOutput): ScanOutput {
            $capturedScan = $input;
            return $scanOutput;
        });
        $client->method('query')->willReturnCallback(static function (QueryInput $input) use (&$capturedQuery, $queryOutput): QueryOutput {
            $capturedQuery = $input;
            return $queryOutput;
        });

        $inventory = new MigrationInventory($client, 'table');
        $inventory->sourcePage(limit: 12);
        $inventory->replayPage(limit: 7);

        self::assertInstanceOf(ScanInput::class, $capturedScan);
        self::assertTrue($capturedScan->getConsistentRead());
        self::assertSame(12, $capturedScan->getLimit());
        self::assertInstanceOf(QueryInput::class, $capturedQuery);
        self::assertFalse($capturedQuery->getConsistentRead());
        self::assertSame('Feed-GlobalPosition-index', $capturedQuery->getIndexName());
        self::assertSame(7, $capturedQuery->getLimit());
    }

    public function test_it_replays_raw_events_with_resumable_gsi_cursors(): void
    {
        $this->append('first', 0);
        $this->append('second', 0);

        $events = [];
        $cursor = null;

        do {
            $page = $this->inventory->replayPage($cursor, 1);
            $events = array_merge($events, $page->events());
            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        self::assertSame(['first', 'second'], array_map(static fn (MigrationEvent $event): string => $event->aggregateId, $events));
        self::assertSame([1, 2], array_map(static fn (MigrationEvent $event): ?int => $event->globalPosition, $events));
    }

    public function test_a_valid_store_with_reserved_position_gaps_passes_audit(): void
    {
        $this->client->updateItem((new InputBuilder())->buildReserveGlobalPositionsInput('table', 5))->resolve();

        $this->append('first', 0);
        $this->append('second', 0);

        $report = (new MigrationReadinessAuditor($this->inventory))->audit();

        self::assertTrue($report->ready());
        self::assertSame(2, $report->sourceEvents);
        self::assertSame(2, $report->replayEvents);
        self::assertSame([], $report->issueCounts);
    }

    public function test_it_identifies_missing_replay_feed(): void
    {
        $this->append('first', 0);

        $this->client->updateItem([
            'TableName' => 'table',
            'Key' => [
                'Id' => new AttributeValue(['S' => 'first']),
                'Playhead' => new AttributeValue(['N' => '0']),
            ],
            'UpdateExpression' => 'REMOVE Feed',
        ])->resolve();

        $report = (new MigrationReadinessAuditor($this->inventory))->audit();

        self::assertFalse($report->ready());
        self::assertSame(1, $report->issueCounts['missing_replay_feed']);
    }

    public function test_it_identifies_duplicate_positions_and_playhead_gaps(): void
    {
        $this->append('first', 0);
        $this->append('first', 2);

        $this->client->updateItem([
            'TableName' => 'table',
            'Key' => [
                'Id' => new AttributeValue(['S' => 'first']),
                'Playhead' => new AttributeValue(['N' => '2']),
            ],
            'UpdateExpression' => 'SET GlobalPosition = :position',
            'ExpressionAttributeValues' => [
                ':position' => new AttributeValue(['N' => '1']),
            ],
        ])->resolve();

        $report = (new MigrationReadinessAuditor($this->inventory))->audit();

        self::assertFalse($report->ready());
        self::assertSame(1, $report->issueCounts['duplicate_global_position']);
        self::assertSame(1, $report->issueCounts['playhead_gap']);
    }

    public function test_it_identifies_a_missing_global_position(): void
    {
        $this->append('first', 0);

        $this->client->updateItem([
            'TableName' => 'table',
            'Key' => [
                'Id' => new AttributeValue(['S' => 'first']),
                'Playhead' => new AttributeValue(['N' => '0']),
            ],
            'UpdateExpression' => 'REMOVE GlobalPosition',
        ])->resolve();

        $events = [];
        $cursor = null;

        do {
            $page = $this->inventory->sourcePage($cursor, 1);
            $events = array_merge($events, $page->events());
            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        self::assertCount(1, $events);
        self::assertNull($events[0]->globalPosition);

        $report = (new MigrationReadinessAuditor($this->inventory))->audit();
        self::assertFalse($report->ready());
        self::assertSame(1, $report->issueCounts['missing_global_position']);
    }

    public function test_a_malformed_source_record_is_not_silently_ignored(): void
    {
        $this->append('first', 0);

        $this->client->updateItem([
            'TableName' => 'table',
            'Key' => [
                'Id' => new AttributeValue(['S' => 'first']),
                'Playhead' => new AttributeValue(['N' => '0']),
            ],
            'UpdateExpression' => 'REMOVE #eventType',
            'ExpressionAttributeNames' => [
                '#eventType' => 'Type',
            ],
        ])->resolve();

        $this->expectException(UnexpectedValueException::class);
        (new MigrationReadinessAuditor($this->inventory))->audit();
    }

    public function test_it_keeps_source_and_replay_cursors_distinct(): void
    {
        $cursor = MigrationCursor::encode([
            'Id' => new AttributeValue(['S' => 'first']),
            'Playhead' => new AttributeValue(['N' => '0']),
        ], 'source');

        self::assertNotNull($cursor);
        self::assertSame('first', MigrationCursor::decode($cursor, 'source')['Id']->getS());

        $this->expectException(InvalidArgumentException::class);
        $this->inventory->replayPage($cursor);
    }

    public function test_it_rejects_invalid_page_sizes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->inventory->sourcePage(limit: 0);
    }

    private function append(string $id, int $playhead): void
    {
        $aggregateId = new TestAggregateId($id);
        $this->store->append($id, new DomainEventStream([
            DomainMessage::recordNow($id, $playhead, new Metadata([]), new TestEvent($aggregateId)),
        ]));
    }
}
