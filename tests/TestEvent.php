<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore\Tests;

use Broadway\Serializer\Serializable;

final class TestEvent implements Serializable
{
    public function __construct(public readonly TestAggregateId $id)
    {
    }

    /**
     * @return array{id: string}
     */
    public function serialize(): array
    {
        return [
            'id' => (string) $this->id,
        ];
    }

    /**
     * @param array{id: string} $data
     */
    public static function deserialize(array $data): static
    {
        return new static(new TestAggregateId($data['id']));
    }
}
