<?php

declare(strict_types=1);

use AsyncAws\DynamoDb\DynamoDbClient;
use chrisjenkinson\DynamoDbEventStore\MigrationInventory;
use chrisjenkinson\DynamoDbEventStore\MigrationReadinessAuditor;

require dirname(__DIR__) . '/vendor/autoload.php';

$table = $argv[1] ?? null;
$workingDirectory = $argv[2] ?? null;

if (!is_string($table) || $table === '') {
    fwrite(STDERR, "Usage: php bin/audit-migration-readiness.php <dynamodb-table> [sqlite-working-directory]\n");
    exit(2);
}

$config = [];
$endpoint = getenv('DYNAMODB_ENDPOINT');

if (is_string($endpoint) && $endpoint !== '') {
    $config['endpoint'] = $endpoint;
}

try {
    $inventory = new MigrationInventory(new DynamoDbClient($config), $table);
    $report = (new MigrationReadinessAuditor($inventory))->audit($workingDirectory);

    echo json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($report->ready() ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Audit could not complete: %s\n", $exception->getMessage()));
    exit(2);
}
