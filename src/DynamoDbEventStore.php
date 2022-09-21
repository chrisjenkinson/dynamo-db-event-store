<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\DynamoDbClient;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
use Broadway\EventStore\EventStore;
use Broadway\EventStore\EventStreamNotFoundException;

final class DynamoDbEventStore implements EventStore
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
            throw new EventStreamNotFoundException(sprintf('Event stream not found for aggregated with id "%s" in table "%s"', $id, $this->table));
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
        $events = iterator_to_array($eventStream);

        $putRequests = array_map(function (DomainMessage $domainMessage): array {
            return [
                'PutRequest' => $this->inputBuilder->buildPutRequest($this->domainMessageNormalizer->normalize($domainMessage)),
            ];
        }, $events);

        $this->client->batchWriteItem($this->inputBuilder->buildBatchWriteItemInput($this->table, $putRequests));
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
}
