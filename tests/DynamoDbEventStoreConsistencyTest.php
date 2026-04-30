<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\Result\QueryOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Broadway\Domain\DomainMessage;
use Broadway\EventStore\EventStreamNotFoundException;
use Broadway\EventStore\EventVisitor;
use Broadway\EventStore\Management\Criteria;
use Broadway\Serializer\SimpleInterfaceSerializer;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;
use PHPUnit\Framework\TestCase;

final class DynamoDbEventStoreConsistencyTest extends TestCase
{
    public function test_it_uses_eventually_consistent_reads_for_aggregate_loads_by_default(): void
    {
        $capturedInput = null;
        $eventStore    = $this->createEventStore($this->createQueryRecordingClient($capturedInput));

        try {
            $eventStore->load(new TestAggregateId('aggregate-id'));
        } catch (EventStreamNotFoundException) {
        }

        self::assertInstanceOf(QueryInput::class, $capturedInput);
        self::assertFalse($capturedInput->getConsistentRead());
    }

    public function test_it_can_use_strongly_consistent_reads_for_aggregate_playhead_loads(): void
    {
        $capturedInput = null;
        $eventStore    = $this->createEventStore($this->createQueryRecordingClient($capturedInput), true);

        $eventStore->loadFromPlayhead(new TestAggregateId('aggregate-id'), 0);

        self::assertInstanceOf(QueryInput::class, $capturedInput);
        self::assertTrue($capturedInput->getConsistentRead());
    }

    public function test_it_uses_the_global_position_query_for_checkpoint_replay(): void
    {
        $capturedInput = null;
        $eventStore    = $this->createEventStore($this->createQueryRecordingClient($capturedInput));

        $lastProcessedGlobalPosition = $eventStore->visitEventsAfterGlobalPosition(
            Criteria::create(),
            7,
            new class() implements EventVisitor {
                public function doWithEvent(DomainMessage $domainMessage): void
                {
                }
            }
        );

        self::assertSame(7, $lastProcessedGlobalPosition);
        self::assertInstanceOf(QueryInput::class, $capturedInput);
        self::assertSame('Feed-GlobalPosition-index', $capturedInput->getIndexName());
        self::assertSame('Feed = :feed AND GlobalPosition > :globalPosition', $capturedInput->getKeyConditionExpression());
        self::assertFalse($capturedInput->getConsistentRead());
    }

    public function test_it_does_not_surface_the_counter_row_during_checkpoint_replay(): void
    {
        $queryResult = $this->createStub(QueryOutput::class);
        $queryResult->method('getItems')->willReturn([
            [
                'Id' => new AttributeValue([
                    'S' => '_counter',
                ]),
                'Playhead' => new AttributeValue([
                    'N' => '0',
                ]),
                'Feed' => new AttributeValue([
                    'S' => 'all',
                ]),
                'GlobalPosition' => new AttributeValue([
                    'N' => '1',
                ]),
            ],
            [
                'Id' => new AttributeValue([
                    'S' => 'aggregate-id',
                ]),
                'Playhead' => new AttributeValue([
                    'N' => '0',
                ]),
                'Feed' => new AttributeValue([
                    'S' => 'all',
                ]),
                'GlobalPosition' => new AttributeValue([
                    'N' => '2',
                ]),
                'Metadata' => new AttributeValue([
                    'M' => [
                        'Class' => new AttributeValue([
                            'S' => 'Broadway\\Domain\\Metadata',
                        ]),
                        'Payload' => new AttributeValue([
                            'S' => '[]',
                        ]),
                    ],
                ]),
                'Payload' => new AttributeValue([
                    'M' => [
                        'Class' => new AttributeValue([
                            'S' => 'chrisjenkinson\\DynamoDbEventStore\\Tests\\TestEvent',
                        ]),
                        'Payload' => new AttributeValue([
                            'S' => '{"id":"aggregate-id"}',
                        ]),
                    ],
                ]),
                'RecordedOn' => new AttributeValue([
                    'S' => '2026-05-01T00:00:00.000000+00:00',
                ]),
                'Type' => new AttributeValue([
                    'S' => 'chrisjenkinson\\DynamoDbEventStore\\Tests\\TestEvent',
                ]),
            ],
        ]);

        $client = $this->createStub(DynamoDbClient::class);
        $client->method('query')->willReturn($queryResult);

        $eventStore = $this->createEventStore($client);
        $visitor    = new CollectingEventVisitor();

        $lastProcessedGlobalPosition = $eventStore->visitEventsAfterGlobalPosition(
            Criteria::create(),
            0,
            $visitor
        );

        self::assertSame(2, $lastProcessedGlobalPosition);
        self::assertCount(1, $visitor->visitedEvents);
        self::assertSame('aggregate-id', $visitor->visitedEvents[0]->getId());
    }

    private function createEventStore(DynamoDbClient $client, bool $aggregateConsistentReads = false): DynamoDbEventStore
    {
        return new DynamoDbEventStore(
            $client,
            new InputBuilder(),
            new DomainMessageNormalizer(new SimpleInterfaceSerializer(), new SimpleInterfaceSerializer(), new JsonEncoder(), new JsonDecoder()),
            'table',
            $aggregateConsistentReads
        );
    }

    private function createQueryRecordingClient(?QueryInput &$capturedInput): DynamoDbClient
    {
        $queryResult = $this->createStub(QueryOutput::class);
        $queryResult->method('getCount')->willReturn(0);
        $queryResult->method('getItems')->willReturn([]);

        $client = $this->createStub(DynamoDbClient::class);

        $client->method('query')->willReturnCallback(static function (QueryInput $queryInput) use (&$capturedInput, $queryResult): QueryOutput {
            $capturedInput = $queryInput;

            return $queryResult;
        });

        return $client;
    }
}
