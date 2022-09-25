<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
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
        private readonly string $table
    ) {
    }

    public function load($id): DomainEventStream
    {
        $id = (string) $id;

        $result = $this->client->query($this->inputBuilder->buildQueryInput($this->table, $id));

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

        $result = $this->client->query($this->inputBuilder->buildQueryWithPlayheadInput($this->table, $id, $playhead));

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

        try {
            array_map(function (DomainMessage $domainMessage): void {
                $this->client->putItem($this->inputBuilder->buildPutItemInput($this->table, $this->domainMessageNormalizer->normalize($domainMessage)));
            }, $events);
        } catch (ConditionalCheckFailedException $e) {
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
        $result = $this->client->scan($this->inputBuilder->buildScanInput($this->table));

        foreach ($result->getItems() as $normalizedDomainMessage) {
            $domainMessage = $this->domainMessageNormalizer->denormalize($normalizedDomainMessage);

            if ($criteria->isMatchedBy($domainMessage)) {
                $eventVisitor->doWithEvent($domainMessage);
            }
        }
    }
}
