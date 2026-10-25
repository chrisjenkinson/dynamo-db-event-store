<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use InvalidArgumentException;
use JsonException;

/** Opaque, serialisable DynamoDB pagination cursors. */
final class MigrationCursor
{
    private const KEY_TYPES = [
        'Id' => 'S',
        'Playhead' => 'N',
        'Feed' => 'S',
        'GlobalPosition' => 'N',
    ];

    /**
     * @param array<string, AttributeValue> $key
     */
    public static function encode(array $key, string $mode): ?string
    {
        if ([] === $key) {
            return null;
        }

        $encoded = [];

        foreach ($key as $name => $attribute) {
            $type = self::KEY_TYPES[$name] ?? null;

            if ($type === null) {
                throw new InvalidArgumentException(sprintf('Unknown pagination key "%s".', $name));
            }

            $value = $type === 'S' ? $attribute->getS() : $attribute->getN();

            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('Invalid pagination key "%s".', $name));
            }

            $encoded[$name] = [$type => $value];
        }

        return base64_encode(json_encode(['mode' => $mode, 'key' => $encoded], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, AttributeValue>
     */
    public static function decode(string $cursor, string $mode): array
    {
        $json = base64_decode($cursor, true);

        if ($json === false) {
            throw new InvalidArgumentException('Invalid migration cursor encoding.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid migration cursor JSON.', 0, $exception);
        }

        if (!is_array($decoded) || ($decoded['mode'] ?? null) !== $mode || !isset($decoded['key']) || !is_array($decoded['key'])) {
            throw new InvalidArgumentException('Migration cursor is invalid or belongs to a different enumeration.');
        }

        $key = [];

        foreach ($decoded['key'] as $name => $attribute) {
            $type = is_string($name) ? (self::KEY_TYPES[$name] ?? null) : null;

            if ($type === null || !is_array($attribute) || count($attribute) !== 1 || !isset($attribute[$type]) || !is_string($attribute[$type])) {
                throw new InvalidArgumentException('Invalid migration cursor key.');
            }

            $key[$name] = new AttributeValue([$type => $attribute[$type]]);
        }

        $required = $mode === 'source' ? ['Id', 'Playhead'] : ['Id', 'Playhead', 'Feed', 'GlobalPosition'];

        if (count($key) !== count($required) || array_diff($required, array_keys($key)) !== []) {
            throw new InvalidArgumentException('Migration cursor has incomplete pagination keys.');
        }

        return $key;
    }
}
