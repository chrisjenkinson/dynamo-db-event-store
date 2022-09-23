<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\DynamoDb\DynamoDbClient;
use Broadway\Domain\DateTime;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use Broadway\EventStore\EventStreamNotFoundException;
use Broadway\Serializer\SimpleInterfaceSerializer;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;
use PHPUnit\Framework\TestCase;

final class DynamoDbEventStoreTest extends TestCase
{
    public function test_it_throws_an_exception_when_there_are_no_events(): void
    {
        $eventStore = $this->createEventStore();

        $this->expectException(EventStreamNotFoundException::class);

        $eventStore->load('id');
    }

    public function test_it_saves_and_loads_an_event_stream(): void
    {
        $eventStore = $this->createEventStore();

        $id = new TestAggregateId('123');

        $event = new TestEvent($id);

        $domainMessage = new DomainMessage($id, 0, new Metadata(), $event, DateTime::fromString('1 Jan 2020'));

        $eventStore->append($id, new DomainEventStream([$domainMessage]));

        $eventStream = $eventStore->load($id);

        $events = iterator_to_array($eventStream);

        $this->assertCount(1, $events);
    }

    public function test_it_does_nothing_when_appending_an_empty_event_stream(): void
    {
        $this->expectException(EventStreamNotFoundException::class);

        $eventStore = $this->createEventStore();

        $id = new TestAggregateId('123');

        $eventStore->append($id, new DomainEventStream([]));

        $eventStore->load($id);
    }

    public function test_it_loads_events_from_a_playhead(): void
    {
        $eventStore = $this->createEventStore();

        $id = new TestAggregateId('123');

        $event1 = new TestEvent($id);
        $event2 = new TestEvent($id);

        $domainMessage1 = new DomainMessage($id, 0, new Metadata(), $event1, DateTime::fromString('1 Jan 2020'));
        $domainMessage2 = new DomainMessage($id, 1, new Metadata(), $event2, DateTime::fromString('2 Jan 2020'));

        $eventStore->append($id, new DomainEventStream([$domainMessage1, $domainMessage2]));

        $eventStream = $eventStore->loadFromPlayhead($id, 1);

        $events = iterator_to_array($eventStream);

        $this->assertCount(1, $events);
    }

    private function createEventStore(): DynamoDbEventStore
    {
        $client = new DynamoDbClient(Configuration::create([
            'endpoint'        => 'http://dynamodb-local:8000',
            'accessKeyId'     => '',
            'accessKeySecret' => '',
        ]));
        $inputBuilder            = new InputBuilder();
        $domainMessageNormalizer = new DomainMessageNormalizer(new SimpleInterfaceSerializer(), new SimpleInterfaceSerializer(), new JsonEncoder(), new JsonDecoder());

        $eventStore = new DynamoDbEventStore($client, $inputBuilder, $domainMessageNormalizer, 'table');

        $eventStore->deleteTable();
        $eventStore->createTable();

        return $eventStore;
    }
}
