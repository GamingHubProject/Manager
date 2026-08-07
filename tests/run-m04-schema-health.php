<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Services/ManagerSchema.php';

use Azuriom\Plugin\GamingHubManager\Services\ManagerSchema;

$failures = [];
$checks = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (! $condition) {
        $failures[] = $message;
    }
};

$tables = array_fill_keys(ManagerSchema::REQUIRED_TABLES, true);
$migrations = array_values(ManagerSchema::TABLE_MIGRATIONS);

$healthy = ManagerSchema::classifyState($tables, $migrations, true, true);
$assert($healthy['schema_state'] === ManagerSchema::READY, 'all tables + all migration records must be READY');
$assert($healthy['schema_ready'] === true, 'READY must set schema_ready=true');
$assert($healthy['missing_tables'] === [], 'healthy state must have no missing tables');

foreach (ManagerSchema::REQUIRED_TABLES as $table) {
    $state = $tables;
    $state[$table] = false;
    $ran = array_values(array_filter(
        $migrations,
        static fn (string $migration): bool => $migration !== ManagerSchema::TABLE_MIGRATIONS[$table],
    ));
    $pending = ManagerSchema::classifyState($state, $ran, true, true);
    $assert($pending['schema_state'] === ManagerSchema::MIGRATIONS_PENDING, $table.' missing without migration record must be MIGRATIONS_PENDING');
    $assert($pending['missing_tables'] === [$table], $table.' must be reported as the sole missing table');
    $assert($pending['pending_migrations'] === [ManagerSchema::TABLE_MIGRATIONS[$table]], $table.' must report its exact pending migration');
}

foreach (ManagerSchema::REQUIRED_TABLES as $table) {
    $state = $tables;
    $state[$table] = false;
    $inconsistent = ManagerSchema::classifyState($state, $migrations, true, true);
    $assert($inconsistent['schema_state'] === ManagerSchema::SCHEMA_INCONSISTENT, $table.' missing with recorded migration must be SCHEMA_INCONSISTENT');
    $assert($inconsistent['recorded_missing_tables'] === [$table], $table.' must be reported as recorded-but-missing');
}

$none = array_fill_keys(ManagerSchema::REQUIRED_TABLES, false);
$allPending = ManagerSchema::classifyState($none, [], true, true);
$assert($allPending['schema_state'] === ManagerSchema::MIGRATIONS_PENDING, 'completely missing unrecorded schema must be MIGRATIONS_PENDING');
$assert($allPending['missing_tables'] === ManagerSchema::REQUIRED_TABLES, 'completely missing schema must report all five tables');
$assert(count($allPending['pending_migrations']) === 5, 'completely missing schema must report all five migrations pending');

$operationsMissing = $tables;
$operationsMissing['gaminghub_manager_operations'] = false;
$historicalRegression = ManagerSchema::classifyState($operationsMissing, $migrations, true, true);
$assert($historicalRegression['schema_state'] === ManagerSchema::SCHEMA_INCONSISTENT, 'recorded operations migration + missing operations table must be SCHEMA_INCONSISTENT');
$assert($historicalRegression['missing_tables'] === ['gaminghub_manager_operations'], 'historical regression must report only gaminghub_manager_operations missing');
$assert($historicalRegression['recorded_missing_tables'] === ['gaminghub_manager_operations'], 'historical regression must identify recorded-but-missing operations table');

$withoutOperationsMigration = array_values(array_filter(
    $migrations,
    static fn (string $migration): bool => $migration !== ManagerSchema::TABLE_MIGRATIONS['gaminghub_manager_operations'],
));
$unexpectedOperationsTable = ManagerSchema::classifyState($tables, $withoutOperationsMigration, true, true);
$assert($unexpectedOperationsTable['schema_state'] === ManagerSchema::SCHEMA_INCONSISTENT, 'existing table without expected migration record must be inconsistent');
$assert($unexpectedOperationsTable['unrecorded_existing_tables'] === ['gaminghub_manager_operations'], 'unexpected existing table must be reported accurately');

$historyUnavailableHealthy = ManagerSchema::classifyState($tables, null, true, false);
$assert($historyUnavailableHealthy['schema_state'] === ManagerSchema::READY, 'physical healthy schema remains runtime-safe when migration history cannot be inspected');
$assert($historyUnavailableHealthy['migration_history_available'] === false, 'history-unavailable status must be explicit');

$historyUnavailableMissing = ManagerSchema::classifyState($operationsMissing, null, true, false);
$assert($historyUnavailableMissing['schema_state'] === ManagerSchema::MIGRATIONS_PENDING, 'missing physical table without inspectable history must not be treated as READY');

$offline = ManagerSchema::classifyState([], null, false, false);
$assert($offline['schema_state'] === ManagerSchema::DATABASE_UNAVAILABLE, 'unavailable database must classify as DATABASE_UNAVAILABLE');
$assert($offline['schema_ready'] === false, 'DATABASE_UNAVAILABLE must not be ready');
$assert($offline['missing_tables'] === ManagerSchema::REQUIRED_TABLES, 'database outage must conservatively report Manager tables unavailable');

if ($failures !== []) {
    fwrite(STDERR, "FAILED: M0.4 schema-health behavior\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- '.$failure."\n");
    }
    exit(1);
}

echo 'PASS: M0.4 schema-health behavior ('.$checks." checks)\n";
