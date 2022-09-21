<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\Input\BatchWriteItemInput;
use AsyncAws\DynamoDb\Input\CreateTableInput;
use AsyncAws\DynamoDb\Input\DeleteTableInput;
use AsyncAws\DynamoDb\Input\DescribeTableInput;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\ValueObject\AttributeDefinition;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\KeySchemaElement;
use AsyncAws\DynamoDb\ValueObject\PutRequest;

final class InputBuilder
{
    public function buildQueryInput(string $tableName, string $id): QueryInput
    {
        return new QueryInput([
            'TableName'                 => $tableName,
            'KeyConditionExpression'    => 'Id = :id',
            'ExpressionAttributeValues' => [
                ':id' => new AttributeValue([
                    'S' => $id,
                ]),
            ],
        ]);
    }

    public function buildQueryWithPlayheadInput(string $tableName, string $id, int $playhead): QueryInput
    {
        return new QueryInput([
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
        ]);
    }

    /**
     * @param array{id: string, playhead: int, metadataClass: string, metadataPayload: string, payloadClass: string, payloadPayload: string, recordedOn: string, type: string} $normalizedDomainMessage
     */
    public function buildPutRequest(array $normalizedDomainMessage): PutRequest
    {
        return new PutRequest([
            'Item' => [

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
        ]);
    }

    /**
     * @param array<array{PutRequest: PutRequest}> $putRequests
     */
    public function buildBatchWriteItemInput(string $tableName, array $putRequests): BatchWriteItemInput
    {
        return new BatchWriteItemInput([
            'RequestItems' => [
                $tableName => $putRequests,
            ],
        ]);
    }
}
