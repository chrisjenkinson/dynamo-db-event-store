<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\Input\CreateTableInput;
use AsyncAws\DynamoDb\Input\DeleteTableInput;
use AsyncAws\DynamoDb\Input\DescribeTableInput;
use AsyncAws\DynamoDb\Input\PutItemInput;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\Input\ScanInput;
use AsyncAws\DynamoDb\Input\TransactWriteItemsInput;
use AsyncAws\DynamoDb\Input\UpdateItemInput;
use AsyncAws\DynamoDb\ValueObject\AttributeDefinition;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\GlobalSecondaryIndex;
use AsyncAws\DynamoDb\ValueObject\KeySchemaElement;
use AsyncAws\DynamoDb\ValueObject\Projection;
use AsyncAws\DynamoDb\ValueObject\Put;
use AsyncAws\DynamoDb\ValueObject\TransactWriteItem;

final class InputBuilder
{
    public function buildQueryInput(string $tableName, string $id, bool $consistentRead = false): QueryInput
    {
        return new QueryInput([
            'ConsistentRead'            => $consistentRead,
            'TableName'                 => $tableName,
            'KeyConditionExpression'    => 'Id = :id',
            'ExpressionAttributeValues' => [
                ':id' => new AttributeValue([
                    'S' => $id,
                ]),
            ],
        ]);
    }

    public function buildQueryWithPlayheadInput(string $tableName, string $id, int $playhead, bool $consistentRead = false): QueryInput
    {
        return new QueryInput([
            'ConsistentRead'            => $consistentRead,
            'TableName'                 => $tableName,
            'KeyConditionExpression'    => 'Id = :id AND Playhead >= :playhead',
            'ExpressionAttributeValues' => [
                ':id' => new AttributeValue([
                    'S' => $id,
                ]),
                ':playhead' => new AttributeValue([
                    'N' => (string) $playhead,
                ]),
            ],
        ]);
    }

    public function buildScanInput(string $tableName): ScanInput
    {
        return new ScanInput([
            'TableName' => $tableName,
        ]);
    }

    public function buildDescribeTableInput(string $tableName): DescribeTableInput
    {
        return new DescribeTableInput([
            'TableName' => $tableName,
        ]);
    }

    public function buildDeleteTableInput(string $tableName): DeleteTableInput
    {
        return new DeleteTableInput([
            'TableName' => $tableName,
        ]);
    }

    public function buildCreateTableInput(string $tableName): CreateTableInput
    {
        return new CreateTableInput([
            'TableName'            => $tableName,
            'AttributeDefinitions' => [
                new AttributeDefinition([
                    'AttributeName' => 'Id',
                    'AttributeType' => 'S',
                ]),
                new AttributeDefinition([
                    'AttributeName' => 'Playhead',
                    'AttributeType' => 'N',
                ]),
                new AttributeDefinition([
                    'AttributeName' => 'Feed',
                    'AttributeType' => 'S',
                ]),
                new AttributeDefinition([
                    'AttributeName' => 'GlobalPosition',
                    'AttributeType' => 'N',
                ]),
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
            'KeySchema'   => [
                new KeySchemaElement([
                    'AttributeName' => 'Id',
                    'KeyType'       => 'HASH',
                ]),
                new KeySchemaElement([
                    'AttributeName' => 'Playhead',
                    'KeyType'       => 'RANGE',
                ]),
            ],
            'GlobalSecondaryIndexes' => [
                new GlobalSecondaryIndex([
                    'IndexName' => 'Feed-GlobalPosition-index',
                    'KeySchema' => [
                        new KeySchemaElement([
                            'AttributeName' => 'Feed',
                            'KeyType'       => 'HASH',
                        ]),
                        new KeySchemaElement([
                            'AttributeName' => 'GlobalPosition',
                            'KeyType'       => 'RANGE',
                        ]),
                    ],
                    'Projection' => new Projection([
                        'ProjectionType' => 'ALL',
                    ]),
                ]),
            ],
        ]);
    }

    public function buildReserveGlobalPositionsInput(string $tableName, int $count): UpdateItemInput
    {
        return new UpdateItemInput([
            'TableName' => $tableName,
            'Key'       => [
                'Id' => new AttributeValue([
                    'S' => '_counter',
                ]),
                'Playhead' => new AttributeValue([
                    'N' => '0',
                ]),
            ],
            'UpdateExpression'         => 'ADD #v :inc',
            'ExpressionAttributeNames' => [
                '#v' => 'Value',
            ],
            'ExpressionAttributeValues' => [
                ':inc' => new AttributeValue([
                    'N' => (string) $count,
                ]),
            ],
            'ReturnValues' => 'UPDATED_NEW',
        ]);
    }

    /**
     * @param array{id: string, playhead: int, metadataClass: string, metadataPayload: string, payloadClass: string, payloadPayload: string, recordedOn: string, type: string, globalPosition: int, feed: string} $normalizedDomainMessage
     */
    public function buildTransactPutItem(string $tableName, array $normalizedDomainMessage): TransactWriteItem
    {
        return new TransactWriteItem([
            'Put' => new Put([
                'TableName'           => $tableName,
                'ConditionExpression' => 'attribute_not_exists(Id)',
                'Item'                => [
                    'Id' => new AttributeValue([
                        'S' => $normalizedDomainMessage['id'],
                    ]),
                    'Playhead' => new AttributeValue([
                        'N' => (string) $normalizedDomainMessage['playhead'],
                    ]),
                    'Feed' => new AttributeValue([
                        'S' => $normalizedDomainMessage['feed'],
                    ]),
                    'GlobalPosition' => new AttributeValue([
                        'N' => (string) $normalizedDomainMessage['globalPosition'],
                    ]),
                    'Metadata' => new AttributeValue([
                        'M' => [
                            'Class' => new AttributeValue([
                                'S' => $normalizedDomainMessage['metadataClass'],
                            ]),
                            'Payload' => new AttributeValue([
                                'S' => $normalizedDomainMessage['metadataPayload'],
                            ]),
                        ],
                    ]),
                    'Payload' => new AttributeValue([
                        'M' => [
                            'Class' => new AttributeValue([
                                'S' => $normalizedDomainMessage['payloadClass'],
                            ]),
                            'Payload' => new AttributeValue([
                                'S' => $normalizedDomainMessage['payloadPayload'],
                            ]),
                        ],
                    ]),
                    'RecordedOn' => new AttributeValue([
                        'S' => $normalizedDomainMessage['recordedOn'],
                    ]),
                    'Type' => new AttributeValue([
                        'S' => $normalizedDomainMessage['type'],
                    ]),
                ],
            ]),
        ]);
    }

    public function buildGlobalReplayInput(string $tableName, ?int $afterGlobalPosition = null): QueryInput
    {
        $keyCondition              = 'Feed = :feed';
        $expressionAttributeValues = [
            ':feed' => new AttributeValue([
                'S' => 'all',
            ]),
        ];

        if (null !== $afterGlobalPosition) {
            $keyCondition .= ' AND GlobalPosition > :globalPosition';
            $expressionAttributeValues[':globalPosition'] = new AttributeValue([
                'N' => (string) $afterGlobalPosition,
            ]);
        }

        return new QueryInput([
            'ConsistentRead'            => false,
            'TableName'                 => $tableName,
            'IndexName'                 => 'Feed-GlobalPosition-index',
            'KeyConditionExpression'    => $keyCondition,
            'ExpressionAttributeValues' => $expressionAttributeValues,
        ]);
    }

    /**
     * @param TransactWriteItem[] $items
     */
    public function buildTransactWriteItemsInput(array $items): TransactWriteItemsInput
    {
        return new TransactWriteItemsInput([
            'TransactItems' => $items,
        ]);
    }

    /**
     * @param array{id: string, playhead: int, metadataClass: string, metadataPayload: string, payloadClass: string, payloadPayload: string, recordedOn: string, type: string} $normalizedDomainMessage
     */
    public function buildPutItemInput(string $tableName, array $normalizedDomainMessage): PutItemInput
    {
        return new PutItemInput([
            'TableName' => $tableName,
            'Item'      => [
                'Id' => new AttributeValue([
                    'S' => $normalizedDomainMessage['id'],
                ]),
                'Playhead' => new AttributeValue([
                    'N' => (string) $normalizedDomainMessage['playhead'],
                ]),
                'Metadata' => new AttributeValue([
                    'M' => [
                        'Class' => new AttributeValue([
                            'S' => $normalizedDomainMessage['metadataClass'],
                        ]),
                        'Payload' => new AttributeValue([
                            'S' => $normalizedDomainMessage['metadataPayload'],
                        ]),
                    ],
                ]),
                'Payload' => new AttributeValue([
                    'M' => [
                        'Class' => new AttributeValue([
                            'S' => $normalizedDomainMessage['payloadClass'],
                        ]),
                        'Payload' => new AttributeValue([
                            'S' => $normalizedDomainMessage['payloadPayload'],
                        ]),
                    ],
                ]),
                'RecordedOn' => new AttributeValue([
                    'S' => $normalizedDomainMessage['recordedOn'],
                ]),
                'Type' => new AttributeValue([
                    'S' => $normalizedDomainMessage['type'],
                ]),
            ],
            'ConditionExpression' => 'attribute_not_exists(Id)',
        ]);
    }
}
