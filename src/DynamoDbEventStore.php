<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use Broadway\Domain\DomainEventStream;
use Broadway\EventStore\EventStreamNotFoundException;
use Broadway\EventStore\EventVisitor;
use Broadway\EventStore\Exception\DuplicatePlayheadException;
use Broadway\EventStore\Management\Criteria;

final class DynamoDbEventStore implements DynamoDbEventStoreInterface
{
    public function __construct(
        private readonly DynamoDbClient $client,
        private readonly InputBuilder $inputBuilder,
        private readonly DomainMessageNormalizer $domainMessageNormalizer,
        private readonly string $table,
        private readonly bool $aggregateConsistentReads = false
    ) {
    }

    public function load($id): DomainEventStream
    {
        $id = (string) $id;

        $result = $this->client->query($this->inputBuilder->buildQueryInput($this->table, $id, $this->aggregateConsistentReads));

        if (0 === $result->getCount()) {
            throw new EventStreamNotFoundException(sprintf('Event stream not found for aggregate with id "%s" in table "%s"', $id, $this->table));
        }

        $events = [];

        foreach ($result->getItems() as $event) {
            $events[] = $this->domainMessageNormalizer->denormalize($event);
        }

        return new DomainEventStream($events);
    }

    public function loadFromPlayhead($id, int $playhead): DomainEventStream
    {
        $id = (string) $id;

        $result = $this->client->query($this->inputBuilder->buildQueryWithPlayheadInput($this->table, $id, $playhead, $this->aggregateConsistentReads));

        $events = [];

        foreach ($result->getItems() as $event) {
            $events[] = $this->domainMessageNormalizer->denormalize($event);
        }

        return new DomainEventStream($events);
    }

    public function append($id, DomainEventStream $eventStream): void
    {
        $id = (string) $id;

        $events = iterator_to_array($eventStream);

        if ([] === $events) {
            return;
        }

        $lastReservedPosition = (int) $this->client
            ->updateItem($this->inputBuilder->buildReserveGlobalPositionsInput($this->table, count($events)))
            ->getAttributes()['Value']
            ->getN();

        $firstPosition = $lastReservedPosition - count($events) + 1;

        $transactItems = [];

        foreach ($events as $index => $domainMessage) {
            $normalized                   = $this->domainMessageNormalizer->normalize($domainMessage);
            $normalized['globalPosition'] = $firstPosition + $index;
            $normalized['feed']           = 'all';
            $transactItems[]              = $this->inputBuilder->buildTransactPutItem($this->table, $normalized);
        }

        try {
            $this->client->transactWriteItems($this->inputBuilder->buildTransactWriteItemsInput($transactItems));
        } catch (ConditionalCheckFailedException | TransactionCanceledException $e) {
            throw new DuplicatePlayheadException($eventStream, $e);
        }
    }

    public function deleteTable(): void
    {
        $result = $this->client->tableNotExists($this->inputBuilder->buildDescribeTableInput($this->table));
        $result->resolve();

        if ($result->isSuccess()) {
            return;
        }

        $this->client->deleteTable($this->inputBuilder->buildDeleteTableInput($this->table));
    }

    public function createTable(): void
    {
        $this->client->createTable($this->inputBuilder->buildCreateTableInput($this->table));

        $this->client->tableExists($this->inputBuilder->buildDescribeTableInput($this->table))->wait();
    }

    public function visitEvents(Criteria $criteria, EventVisitor $eventVisitor): void
    {
        $checkpoint = 0;

        do {
            $page = $this->loadReplayPageAfterGlobalPosition($criteria, $checkpoint, 1000);

            foreach ($page->events() as $event) {
                $eventVisitor->doWithEvent($event->message());
            }

            $checkpoint = $page->lastProcessedGlobalPosition();
        } while ($page->hasMore());
    }

    public function loadReplayPageAfterGlobalPosition(Criteria $criteria, int $afterGlobalPosition, int $limit): ReplayPage
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Replay page limit must be at least 1.');
        }

        $lastProcessedGlobalPosition = $afterGlobalPosition;
        $events                      = [];
        $result                      = $this->client->query($this->inputBuilder->buildGlobalReplayInput($this->table, $afterGlobalPosition, $limit));

        foreach ($result->getItems(true) as $normalizedDomainMessage) {
            if ('_counter' === $normalizedDomainMessage['Id']->getS()) {
                continue;
            }

            $lastProcessedGlobalPosition = (int) $normalizedDomainMessage['GlobalPosition']->getN();
            $domainMessage               = $this->domainMessageNormalizer->denormalize($normalizedDomainMessage);

            if ($criteria->isMatchedBy($domainMessage)) {
                $events[] = new ReplayEvent($domainMessage, $lastProcessedGlobalPosition);
            }
        }

        return new ReplayPage($events, $lastProcessedGlobalPosition, [] !== $result->getLastEvaluatedKey());
    }
}
