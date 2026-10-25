<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\Input\ScanInput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use InvalidArgumentException;

/**
 * A read-only migration inventory. Use only after source writes have been stopped:
 * even a strongly consistent DynamoDB scan is not a point-in-time snapshot.
 */
final class MigrationInventory
{
    public function __construct(
        private readonly DynamoDbClient $client,
        private readonly string $table
    ) {
    }

    public function sourcePage(?string $cursor = null, int $limit = 100): MigrationInventoryPage
    {
        $this->assertLimit($limit);

        $input = [
            'TableName' => $this->table,
            'ConsistentRead' => true,
            'Limit' => $limit,
        ];

        if ($cursor !== null) {
            $input['ExclusiveStartKey'] = MigrationCursor::decode($cursor, 'source');
        }

        $result = $this->client->scan(new ScanInput($input));
        $events = [];

        foreach ($result->getItems() as $item) {
            // The legacy sequence counter has no event payload.
            if (($item['Id'] ?? null)?->getS() === '_counter' && ($item['Playhead'] ?? null)?->getN() === '0') {
                continue;
            }

            $events[] = MigrationEvent::fromItem($item);
        }

        return new MigrationInventoryPage($events, MigrationCursor::encode($result->getLastEvaluatedKey(), 'source'));
    }

    /**
     * Reads the legacy GSI in global reservation order, without deserialising events.
     * This is NOT a strongly consistent enumeration; verify it against sourcePage()
     * after writes stop before using it for a migration.
     */
    public function replayPage(?string $cursor = null, int $limit = 100): MigrationInventoryPage
    {
        $this->assertLimit($limit);

        $input = [
            'TableName' => $this->table,
            'IndexName' => 'Feed-GlobalPosition-index',
            'KeyConditionExpression' => 'Feed = :feed',
            'ExpressionAttributeValues' => [
                ':feed' => new AttributeValue(['S' => 'all']),
            ],
            'ConsistentRead' => false,
            'Limit' => $limit,
            'ScanIndexForward' => true,
        ];

        if ($cursor !== null) {
            $input['ExclusiveStartKey'] = MigrationCursor::decode($cursor, 'replay');
        }

        $result = $this->client->query(new QueryInput($input));
        $events = [];

        foreach ($result->getItems() as $item) {
            $events[] = MigrationEvent::fromItem($item);
        }

        return new MigrationInventoryPage($events, MigrationCursor::encode($result->getLastEvaluatedKey(), 'replay'));
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Migration page limit must be between 1 and 1000.');
        }
    }
}
