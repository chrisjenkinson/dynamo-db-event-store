<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use UnexpectedValueException;

/**
 * Raw stored event data for export. No Broadway event classes need to be available to read it.
 */
final class MigrationEvent
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $playhead,
        public readonly ?int $globalPosition,
        public readonly ?string $feed,
        public readonly string $eventType,
        public readonly string $recordedOn,
        public readonly string $metadataClass,
        public readonly string $metadataPayload,
        public readonly string $payloadClass,
        public readonly string $payloadPayload
    ) {
    }

    /**
     * @param array<string, AttributeValue> $item
     */
    public static function fromItem(array $item): self
    {
        try {
            $metadata = self::map($item, 'Metadata');
            $payload = self::map($item, 'Payload');

            return new self(
                self::string($item, 'Id'),
                self::number($item, 'Playhead'),
                isset($item['GlobalPosition']) ? self::number($item, 'GlobalPosition') : null,
                isset($item['Feed']) ? self::string($item, 'Feed') : null,
                self::string($item, 'Type'),
                self::string($item, 'RecordedOn'),
                self::string($metadata, 'Class'),
                self::string($metadata, 'Payload'),
                self::string($payload, 'Class'),
                self::string($payload, 'Payload')
            );
        } catch (UnexpectedValueException $exception) {
            $id = ($item['Id'] ?? null)?->getS() ?? '[missing]';
            $playhead = ($item['Playhead'] ?? null)?->getN() ?? '[missing]';

            throw new UnexpectedValueException(sprintf(
                'Invalid stored event Id="%s", Playhead="%s": %s',
                $id,
                $playhead,
                $exception->getMessage()
            ), 0, $exception);
        }
    }

    /**
     * A stable fingerprint for comparing a base-table row with its GSI projection.
     * It does not deserialize or re-encode either payload.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->aggregateId,
            $this->playhead,
            $this->globalPosition,
            $this->feed,
            $this->eventType,
            $this->recordedOn,
            $this->metadataClass,
            $this->metadataPayload,
            $this->payloadClass,
            $this->payloadPayload,
        ], JSON_THROW_ON_ERROR));
    }

    public function identity(): string
    {
        return sprintf('%s (playhead %d)', $this->aggregateId, $this->playhead);
    }

    /**
     * @param array<string, AttributeValue> $item
     */
    private static function string(array $item, string $name): string
    {
        $attribute = $item[$name] ?? null;
        $value = $attribute instanceof AttributeValue ? $attribute->getS() : null;

        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Missing or invalid string attribute "%s" in stored event.', $name));
        }

        return $value;
    }

    /**
     * @param array<string, AttributeValue> $item
     */
    private static function number(array $item, string $name): int
    {
        $attribute = $item[$name] ?? null;
        $value = $attribute instanceof AttributeValue ? $attribute->getN() : null;

        if (!is_string($value) || !ctype_digit($value) || (string) (int) $value !== $value) {
            throw new UnexpectedValueException(sprintf('Missing or invalid integer attribute "%s" in stored event.', $name));
        }

        return (int) $value;
    }

    /**
     * @param array<string, AttributeValue> $item
     *
     * @return array<string, AttributeValue>
     */
    private static function map(array $item, string $name): array
    {
        $attribute = $item[$name] ?? null;

        if (!$attribute instanceof AttributeValue) {
            throw new UnexpectedValueException(sprintf('Missing or invalid map attribute "%s" in stored event.', $name));
        }

        return $attribute->getM();
    }
}
