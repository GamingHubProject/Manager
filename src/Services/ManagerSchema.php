<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDOException;

final class ManagerSchema
{
    public const READY = 'READY';
    public const MIGRATIONS_PENDING = 'MIGRATIONS_PENDING';
    public const SCHEMA_INCONSISTENT = 'SCHEMA_INCONSISTENT';
    public const DATABASE_UNAVAILABLE = 'DATABASE_UNAVAILABLE';

    public const TABLE_MIGRATIONS = [
        'gaminghub_manager_sources' => '2026_08_06_000000_create_gaminghub_manager_sources_table',
        'gaminghub_manager_packages' => '2026_08_06_001000_create_gaminghub_manager_packages_table',
        'gaminghub_manager_operations' => '2026_08_06_002000_create_gaminghub_manager_operations_table',
        'gaminghub_manager_backups' => '2026_08_06_003000_create_gaminghub_manager_backups_table',
        'gaminghub_manager_settings' => '2026_08_06_004000_create_gaminghub_manager_settings_table',
    ];

    public const REQUIRED_TABLES = [
        'gaminghub_manager_sources',
        'gaminghub_manager_packages',
        'gaminghub_manager_operations',
        'gaminghub_manager_backups',
        'gaminghub_manager_settings',
    ];

    private ?array $status = null;

    /**
     * @return array{
     *     state: string,
     *     schema_state: string,
     *     schema_ready: bool,
     *     database_available: bool,
     *     migration_history_available: bool,
     *     missing_tables: list<string>,
     *     pending_migrations: list<string>,
     *     recorded_missing_tables: list<string>,
     *     unrecorded_existing_tables: list<string>,
     *     relevant_recorded_migrations: list<string>
     * }
     */
    public function status(bool $refresh = false): array
    {
        if (! $refresh && $this->status !== null) {
            return $this->status;
        }
        if ($refresh) {
            $this->status = null;
        }

        try {
            DB::connection()->getPdo();
        } catch (QueryException|PDOException $exception) {
            if ($exception instanceof QueryException && ! $this->isConnectionFailure($exception)) {
                throw $exception;
            }

            return $this->status = self::classifyState([], null, false, false);
        }

        $tableState = [];
        foreach (self::REQUIRED_TABLES as $table) {
            $exists = $this->tableExists($table);
            if (($this->status['database_available'] ?? true) === false) {
                return $this->status;
            }
            $tableState[$table] = $exists;
        }

        [$migrationHistoryAvailable, $ranMigrations] = $this->migrationHistory();
        if (($this->status['database_available'] ?? true) === false) {
            return $this->status;
        }

        $status = self::classifyState(
            $tableState,
            $ranMigrations,
            true,
            $migrationHistoryAvailable,
        );

        if ($status['schema_state'] === self::SCHEMA_INCONSISTENT) {
            Log::warning('Gaming Hub Manager schema history is inconsistent with physical tables.', [
                'schema_state' => $status['schema_state'],
                'missing_tables' => $status['missing_tables'],
                'recorded_missing_tables' => $status['recorded_missing_tables'],
                'unrecorded_existing_tables' => $status['unrecorded_existing_tables'],
                'relevant_recorded_migrations' => $status['relevant_recorded_migrations'],
            ]);
        }

        return $this->status = $status;
    }

    public function ready(bool $refresh = false): bool
    {
        return $this->status($refresh)['schema_ready'];
    }

    public function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (QueryException $exception) {
            $state = $this->sqlState($exception);
            if ($state === '42P01') {
                return false;
            }
            if ($this->isConnectionFailure($exception)) {
                $this->status = self::classifyState([], null, false, false);

                return false;
            }

            throw $exception;
        } catch (PDOException $exception) {
            $state = $this->sqlState($exception);
            if ($state === '42P01') {
                return false;
            }
            if ($this->isConnectionFailure($exception)) {
                $this->status = self::classifyState([], null, false, false);

                return false;
            }

            throw $exception;
        }
    }

    /**
     * Pure schema-state classifier used by runtime code and standalone regression tests.
     *
     * @param array<string, bool> $tableState
     * @param list<string>|null $ranMigrations
     * @return array{
     *     state: string,
     *     schema_state: string,
     *     schema_ready: bool,
     *     database_available: bool,
     *     migration_history_available: bool,
     *     missing_tables: list<string>,
     *     pending_migrations: list<string>,
     *     recorded_missing_tables: list<string>,
     *     unrecorded_existing_tables: list<string>,
     *     relevant_recorded_migrations: list<string>
     * }
     */
    public static function classifyState(
        array $tableState,
        ?array $ranMigrations,
        bool $databaseAvailable = true,
        bool $migrationHistoryAvailable = true,
    ): array {
        if (! $databaseAvailable) {
            return [
                'state' => self::DATABASE_UNAVAILABLE,
                'schema_state' => self::DATABASE_UNAVAILABLE,
                'schema_ready' => false,
                'database_available' => false,
                'migration_history_available' => false,
                'missing_tables' => self::REQUIRED_TABLES,
                'pending_migrations' => [],
                'recorded_missing_tables' => [],
                'unrecorded_existing_tables' => [],
                'relevant_recorded_migrations' => [],
            ];
        }

        $missingTables = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (! ($tableState[$table] ?? false)) {
                $missingTables[] = $table;
            }
        }

        $recordedMissingTables = [];
        $unrecordedExistingTables = [];
        $pendingMigrations = [];
        $relevantRecordedMigrations = [];
        $ranLookup = array_fill_keys($ranMigrations ?? [], true);

        if ($migrationHistoryAvailable && $ranMigrations !== null) {
            foreach (self::TABLE_MIGRATIONS as $table => $migration) {
                $exists = (bool) ($tableState[$table] ?? false);
                $recorded = isset($ranLookup[$migration]);

                if ($recorded) {
                    $relevantRecordedMigrations[] = $migration;
                }
                if (! $exists && $recorded) {
                    $recordedMissingTables[] = $table;
                } elseif (! $exists) {
                    $pendingMigrations[] = $migration;
                } elseif (! $recorded) {
                    $unrecordedExistingTables[] = $table;
                }
            }
        }

        if ($migrationHistoryAvailable
            && ($recordedMissingTables !== [] || $unrecordedExistingTables !== [])) {
            $state = self::SCHEMA_INCONSISTENT;
        } elseif ($missingTables !== []) {
            $state = self::MIGRATIONS_PENDING;
        } else {
            $state = self::READY;
        }

        return [
            'state' => $state,
            'schema_state' => $state,
            'schema_ready' => $state === self::READY,
            'database_available' => true,
            'migration_history_available' => $migrationHistoryAvailable,
            'missing_tables' => $missingTables,
            'pending_migrations' => $pendingMigrations,
            'recorded_missing_tables' => $recordedMissingTables,
            'unrecorded_existing_tables' => $unrecordedExistingTables,
            'relevant_recorded_migrations' => $relevantRecordedMigrations,
        ];
    }

    /**
     * @return array{0: bool, 1: list<string>|null}
     */
    private function migrationHistory(): array
    {
        try {
            $repository = app('migration.repository');
            if (! is_object($repository)
                || ! method_exists($repository, 'repositoryExists')
                || ! method_exists($repository, 'getRan')) {
                return [false, null];
            }
            if (! $repository->repositoryExists()) {
                return [false, null];
            }

            $ran = $repository->getRan();
            if (! is_array($ran)) {
                return [false, null];
            }

            return [true, array_values(array_filter($ran, 'is_string'))];
        } catch (QueryException $exception) {
            $state = $this->sqlState($exception);
            if ($state === '42P01') {
                return [false, null];
            }
            if ($this->isConnectionFailure($exception)) {
                $this->status = self::classifyState([], null, false, false);

                return [false, null];
            }

            throw $exception;
        } catch (PDOException $exception) {
            $state = $this->sqlState($exception);
            if ($state === '42P01') {
                return [false, null];
            }
            if ($this->isConnectionFailure($exception)) {
                $this->status = self::classifyState([], null, false, false);

                return [false, null];
            }

            throw $exception;
        }
    }

    private function isConnectionFailure(\Throwable $exception): bool
    {
        return str_starts_with($this->sqlState($exception), '08');
    }

    private function sqlState(\Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return (string) ($exception->errorInfo[0] ?? $exception->getCode());
        }
        if ($exception instanceof PDOException && is_array($exception->errorInfo ?? null)) {
            return (string) ($exception->errorInfo[0] ?? $exception->getCode());
        }

        return (string) $exception->getCode();
    }
}
