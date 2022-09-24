<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\DynamoDb\DynamoDbClient;
use Broadway\Domain\DomainEventStream;
use Broadway\EventStore\EventStreamNotFoundException;
use Broadway\EventStore\Testing\EventStoreTest;
use Broadway\Serializer\SimpleInterfaceSerializer;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;

final class DynamoDbEventStoreTest extends EventStoreTest
{
    /**
     * @dataProvider idDataProvider
     * @param mixed $id
     */
    public function test_it_does_nothing_when_appending_an_empty_event_stream($id): void
    {
        $this->expectException(EventStreamNotFoundException::class);

        $this->eventStore->append($id, new DomainEventStream([]));

        $this->eventStore->load($id);
    }

    protected function setUp(): void
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

        $this->eventStore = $eventStore;
    }
}
