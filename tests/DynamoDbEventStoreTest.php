<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\Input\TransactWriteItemsInput;
use AsyncAws\DynamoDb\Result\TransactWriteItemsOutput;
use AsyncAws\DynamoDb\Result\UpdateItemOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use Broadway\EventStore\EventStreamNotFoundException;
use Broadway\EventStore\Exception\DuplicatePlayheadException;
use Broadway\EventStore\Testing\EventStoreTest;
use Broadway\Serializer\SimpleInterfaceSerializer;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;

final class DynamoDbEventStoreTest extends EventStoreTest
{
    private TestAggregateId $aggregateId;

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

    public function test_it_persists_global_position_metadata_for_each_event(): void
    {
        $capturedTransactItems = [];

        $updateOutput = $this->createStub(UpdateItemOutput::class);
        $updateOutput->method('getAttributes')->willReturn([
            'Value' => new AttributeValue([
                'N' => '1',
            ]),
        ]);

        $client = $this->createStub(DynamoDbClient::class);
        $client->method('updateItem')->willReturn($updateOutput);
        $client->method('transactWriteItems')->willReturnCallback(
            function ($input) use (&$capturedTransactItems): TransactWriteItemsOutput {
                $capturedTransactItems = TransactWriteItemsInput::create($input)->getTransactItems();

                return $this->createStub(TransactWriteItemsOutput::class);
            }
        );

        $eventStore = $this->createEventStoreWithClient($client);

        $eventStore->append($this->aggregateId, new DomainEventStream([
            $this->buildDomainMessage(0),
            $this->buildDomainMessage(1),
        ]));

        $firstPut  = $capturedTransactItems[0]->getPut();
        $secondPut = $capturedTransactItems[1]->getPut();

        self::assertNotNull($firstPut);
        self::assertNotNull($secondPut);

        self::assertSame('all', $firstPut->getItem()['Feed']->getS());
        self::assertSame('0', $firstPut->getItem()['GlobalPosition']->getN());
        self::assertSame('1', $secondPut->getItem()['GlobalPosition']->getN());
    }

    public function test_it_translates_transaction_conflicts_to_duplicate_playhead(): void
    {
        $updateOutput = $this->createStub(UpdateItemOutput::class);
        $updateOutput->method('getAttributes')->willReturn([
            'Value' => new AttributeValue([
                'N' => '0',
            ]),
        ]);

        $client = $this->createStub(DynamoDbClient::class);
        $client->method('updateItem')->willReturn($updateOutput);
        /** @var TransactionCanceledException $transactionException */
        $transactionException = (new \ReflectionClass(TransactionCanceledException::class))->newInstanceWithoutConstructor();
        $client->method('transactWriteItems')->willThrowException($transactionException);

        $eventStore = $this->createEventStoreWithClient($client);

        $this->expectException(DuplicatePlayheadException::class);

        $eventStore->append($this->aggregateId, new DomainEventStream([
            $this->buildDomainMessage(0),
        ]));
    }

    protected function setUp(): void
    {
        $this->aggregateId = new TestAggregateId('aggregate-id');

        $client = new DynamoDbClient(Configuration::create([
            'endpoint'        => (string) getenv('DYNAMODB_ENDPOINT'),
            'accessKeyId'     => (string) getenv('DYNAMODB_ACCESS_KEY_ID'),
            'accessKeySecret' => (string) getenv('DYNAMODB_SECRET_ACCESS_KEY'),
        ]));

        $this->eventStore = $this->createEventStoreWithClient($client, true);
    }

    private function createEventStoreWithClient(DynamoDbClient $client, bool $createTable = false): DynamoDbEventStore
    {
        $inputBuilder            = new InputBuilder();
        $domainMessageNormalizer = new DomainMessageNormalizer(new SimpleInterfaceSerializer(), new SimpleInterfaceSerializer(), new JsonEncoder(), new JsonDecoder());
        $eventStore              = new DynamoDbEventStore($client, $inputBuilder, $domainMessageNormalizer, 'table');

        if ($createTable) {
            $eventStore->deleteTable();
            $eventStore->createTable();
        }

        return $eventStore;
    }

    private function buildDomainMessage(int $playhead): DomainMessage
    {
        return DomainMessage::recordNow((string) $this->aggregateId, $playhead, new Metadata([]), new TestEvent($this->aggregateId));
    }
}
