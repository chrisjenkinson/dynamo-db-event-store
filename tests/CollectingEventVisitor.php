<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use Broadway\Domain\DomainMessage;
use Broadway\EventStore\EventVisitor;

final class CollectingEventVisitor implements EventVisitor
{
    /**
     * @var list<DomainMessage>
     */
    public array $visitedEvents = [];

    public function doWithEvent(DomainMessage $domainMessage): void
    {
        $this->visitedEvents[] = $domainMessage;
    }
}
