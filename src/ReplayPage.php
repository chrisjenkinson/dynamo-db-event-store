<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

final class ReplayPage
{
    /**
     * @param list<ReplayEvent> $events
     */
    public function __construct(
        private readonly array $events,
        private readonly int $lastProcessedGlobalPosition,
        private readonly bool $hasMore
    ) {
    }

    /**
     * @return list<ReplayEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function lastProcessedGlobalPosition(): int
    {
        return $this->lastProcessedGlobalPosition;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }
}
