<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use Broadway\EventStore\Management\Testing\Start;
use chrisjenkinson\DynamoDbEventStore\ReplayEvent;
use chrisjenkinson\DynamoDbEventStore\ReplayPage;
use PHPUnit\Framework\TestCase;

final class ReplayPageTest extends TestCase
{
    public function test_it_exposes_replay_events_checkpoint_and_has_more(): void
    {
        $message = DomainMessage::recordNow('aggregate-id', 0, new Metadata([]), new Start());
        $event   = new ReplayEvent($message, 12);
        $page    = new ReplayPage([$event], 12, true);

        self::assertSame([$event], $page->events());
        self::assertSame(12, $page->lastProcessedGlobalPosition());
        self::assertTrue($page->hasMore());
        self::assertSame($message, $event->message());
        self::assertSame(12, $event->globalPosition());
    }
}
