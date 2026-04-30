<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\Result\QueryOutput;
use Broadway\EventStore\EventStreamNotFoundException;
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
