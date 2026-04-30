<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use Broadway\EventStore\EventStore;
use Broadway\EventStore\EventVisitor;
use Broadway\EventStore\Management\Criteria;
use Broadway\EventStore\Management\EventStoreManagement;

interface DynamoDbEventStoreInterface extends EventStore, EventStoreManagement
{
    public function createTable(): void;

    public function deleteTable(): void;

    public function visitEventsAfterGlobalPosition(Criteria $criteria, int $afterGlobalPosition, EventVisitor $eventVisitor): int;
}
