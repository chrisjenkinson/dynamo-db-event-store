<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use UnexpectedValueException;

final class UnexpectedDomainMessageContent extends UnexpectedValueException
{
    public function __construct(string $data, string $expectedType, mixed $result)
    {
        parent::__construct(sprintf('Expected "%s" to be "%s", instead got "%s".', $data, $expectedType, get_debug_type($result)));
    }
}
