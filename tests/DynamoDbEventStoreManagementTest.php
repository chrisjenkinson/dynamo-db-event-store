<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\DynamoDb\DynamoDbClient;
use Broadway\EventStore\Management\EventStoreManagement;
use Broadway\EventStore\Management\Testing\EventStoreManagementTest;
use Broadway\Serializer\SimpleInterfaceSerializer;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;

final class DynamoDbEventStoreManagementTest extends EventStoreManagementTest
{
    protected function createEventStore(): EventStoreManagement
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
