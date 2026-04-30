<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use Broadway\EventStore\EventStore;
use Broadway\EventStore\Management\Criteria;
use Broadway\EventStore\Management\EventStoreManagement;

interface DynamoDbEventStoreInterface extends EventStore, EventStoreManagement
{
    public function createTable(): void;

    public function deleteTable(): void;

    public function loadReplayPageAfterGlobalPosition(Criteria $criteria, int $afterGlobalPosition, int $limit): ReplayPage;
}
