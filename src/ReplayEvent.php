<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use Broadway\Domain\DomainMessage;

final class ReplayEvent
{
    public function __construct(
        private readonly DomainMessage $message,
        private readonly int $globalPosition
    ) {
    }

    public function message(): DomainMessage
    {
        return $this->message;
    }

    public function globalPosition(): int
    {
        return $this->globalPosition;
    }
}
