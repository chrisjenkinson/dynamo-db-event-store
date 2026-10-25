<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

final class MigrationInventoryPage
{
    /**
     * @param list<MigrationEvent> $events
     */
    public function __construct(
        private readonly array $events,
        private readonly ?string $nextCursor
    ) {
    }

    /**
     * @return list<MigrationEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }
}
