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
    public function test_it_creates_a_global_position_index_for_replay(): void
    {
        $inputBuilder = new InputBuilder();
        $input        = $inputBuilder->buildCreateTableInput('table');

        self::assertSame('Feed', $input->getGlobalSecondaryIndexes()[0]->getKeySchema()[0]->getAttributeName());
        self::assertSame('GlobalPosition', $input->getGlobalSecondaryIndexes()[0]->getKeySchema()[1]->getAttributeName());
    }

    protected function createEventStore(): EventStoreManagement
    {
        $client = new DynamoDbClient(Configuration::create([
            'endpoint'        => (string) getenv('DYNAMODB_ENDPOINT'),
            'accessKeyId'     => (string) getenv('DYNAMODB_ACCESS_KEY_ID'),
            'accessKeySecret' => (string) getenv('DYNAMODB_SECRET_ACCESS_KEY'),
        ]));
        $inputBuilder            = new InputBuilder();
        $domainMessageNormalizer = new DomainMessageNormalizer(new SimpleInterfaceSerializer(), new SimpleInterfaceSerializer(), new JsonEncoder(), new JsonDecoder());

        $eventStore = new DynamoDbEventStore($client, $inputBuilder, $domainMessageNormalizer, 'table');

        $eventStore->deleteTable();
        $eventStore->createTable();

        return $eventStore;
    }
}
