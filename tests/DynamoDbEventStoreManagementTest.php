<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\DynamoDb\DynamoDbClient;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use Broadway\EventStore\Management\Criteria;
use Broadway\EventStore\Management\EventStoreManagement;
use Broadway\EventStore\Management\Testing\EventStoreManagementTest;
use Broadway\EventStore\Management\Testing\RecordingEventVisitor;
use Broadway\EventStore\Management\Testing\Start;
use Broadway\Serializer\SimpleInterfaceSerializer;
use chrisjenkinson\DynamoDbEventStore\DomainMessageNormalizer;
use chrisjenkinson\DynamoDbEventStore\DynamoDbEventStore;
use chrisjenkinson\DynamoDbEventStore\InputBuilder;
use chrisjenkinson\DynamoDbEventStore\JsonDecoder;
use chrisjenkinson\DynamoDbEventStore\JsonEncoder;

final class DynamoDbEventStoreManagementTest extends EventStoreManagementTest
{
    private DynamoDbEventStore $dynamoEventStore;

    public function test_it_queries_events_by_global_position_for_replay(): void
    {
        $inputBuilder = new InputBuilder();
        $input        = $inputBuilder->buildGlobalReplayInput('table');

        self::assertSame('Feed-GlobalPosition-index', $input->getIndexName());
        self::assertSame('Feed = :feed', $input->getKeyConditionExpression());
        self::assertSame('all', $input->getExpressionAttributeValues()[':feed']->getS());
    }

    public function test_it_visits_events_in_global_position_order(): void
    {
        $aggregateIdB = 'aggregate-b';
        $aggregateIdA = 'aggregate-a';

        $this->dynamoEventStore->append($aggregateIdB, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdB, 0, new Metadata([]), new Start()),
        ]));
        $this->dynamoEventStore->append($aggregateIdA, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdA, 0, new Metadata([]), new Start()),
        ]));

        $visitor = new RecordingEventVisitor();
        $this->dynamoEventStore->visitEvents(Criteria::create(), $visitor);
        $visitedEvents = $visitor->getVisitedEvents();

        $lastTwo = array_slice($visitedEvents, -2);

        self::assertSame($aggregateIdB, $lastTwo[0]->getId());
        self::assertSame($aggregateIdA, $lastTwo[1]->getId());
    }

    public function test_it_loads_the_first_replay_page_in_global_position_order(): void
    {
        $aggregateIdB = 'aggregate-b';
        $aggregateIdA = 'aggregate-a';

        $this->dynamoEventStore->append($aggregateIdB, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdB, 0, new Metadata([]), new Start()),
        ]));
        $this->dynamoEventStore->append($aggregateIdA, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdA, 0, new Metadata([]), new Start()),
        ]));

        $page   = $this->dynamoEventStore->loadReplayPageAfterGlobalPosition(Criteria::create(), 24, 2);
        $events = $page->events();

        self::assertCount(2, $events);
        self::assertSame($aggregateIdB, $events[0]->message()->getId());
        self::assertSame(25, $events[0]->globalPosition());
        self::assertSame($aggregateIdA, $events[1]->message()->getId());
        self::assertSame(26, $events[1]->globalPosition());
        self::assertSame(26, $page->lastProcessedGlobalPosition());
    }

    public function test_it_loads_a_later_page_after_a_checkpoint(): void
    {
        $aggregateId = 'aggregate-a';

        $this->dynamoEventStore->append($aggregateId, new DomainEventStream([
            DomainMessage::recordNow($aggregateId, 0, new Metadata([]), new Start()),
            DomainMessage::recordNow($aggregateId, 1, new Metadata([]), new Start()),
            DomainMessage::recordNow($aggregateId, 2, new Metadata([]), new Start()),
        ]));

        $page   = $this->dynamoEventStore->loadReplayPageAfterGlobalPosition(Criteria::create(), 25, 2);
        $events = $page->events();

        self::assertCount(2, $events);
        self::assertSame(26, $events[0]->globalPosition());
        self::assertSame(27, $events[1]->globalPosition());
    }

    public function test_it_can_query_replay_events_after_a_checkpoint(): void
    {
        $inputBuilder = new InputBuilder();
        $input        = $inputBuilder->buildGlobalReplayInput('table', 7);

        self::assertSame('Feed = :feed AND GlobalPosition > :globalPosition', $input->getKeyConditionExpression());
        self::assertSame('7', $input->getExpressionAttributeValues()[':globalPosition']->getN());
    }

    public function test_it_can_query_replay_events_after_a_checkpoint_with_a_limit(): void
    {
        $inputBuilder = new InputBuilder();
        $input        = $inputBuilder->buildGlobalReplayInput('table', 7, 3);

        self::assertSame('Feed = :feed AND GlobalPosition > :globalPosition', $input->getKeyConditionExpression());
        self::assertSame('7', $input->getExpressionAttributeValues()[':globalPosition']->getN());
        self::assertSame(3, $input->getLimit());
    }

    public function test_it_advances_the_checkpoint_past_filtered_events(): void
    {
        $aggregateIdA = 'aggregate-a';
        $aggregateIdB = 'aggregate-b';

        $this->dynamoEventStore->append($aggregateIdA, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdA, 0, new Metadata([]), new Start()),
        ]));
        $this->dynamoEventStore->append($aggregateIdB, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdB, 0, new Metadata([]), new Start()),
            DomainMessage::recordNow($aggregateIdB, 1, new Metadata([]), new \Broadway\EventStore\Management\Testing\Middle('x')),
        ]));

        $page = $this->dynamoEventStore->loadReplayPageAfterGlobalPosition(
            Criteria::create()->withEventTypes([
                'Broadway.EventStore.Management.Testing.Start',
            ]),
            25,
            2
        );

        self::assertSame(27, $page->lastProcessedGlobalPosition());
        self::assertCount(1, $page->events());
        self::assertSame($aggregateIdB, $page->events()[0]->message()->getId());
        self::assertSame(0, $page->events()[0]->message()->getPlayhead());
    }

    public function test_it_visits_all_events_by_draining_replay_pages(): void
    {
        $aggregateIdB = 'aggregate-b';
        $aggregateIdA = 'aggregate-a';

        $this->dynamoEventStore->append($aggregateIdB, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdB, 0, new Metadata([]), new Start()),
        ]));
        $this->dynamoEventStore->append($aggregateIdA, new DomainEventStream([
            DomainMessage::recordNow($aggregateIdA, 0, new Metadata([]), new Start()),
        ]));

        $visitor = new RecordingEventVisitor();
        $this->dynamoEventStore->visitEvents(Criteria::create(), $visitor);

        $visitedEvents = $visitor->getVisitedEvents();
        $lastTwo       = array_slice($visitedEvents, -2);

        self::assertSame($aggregateIdB, $lastTwo[0]->getId());
        self::assertSame($aggregateIdA, $lastTwo[1]->getId());
    }

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

        $this->dynamoEventStore = $eventStore;

        return $eventStore;
    }
}
