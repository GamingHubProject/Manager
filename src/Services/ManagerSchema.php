<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;

final class ManagerSchema
{
    public const REQUIRED_TABLES = [
        'gaminghub_manager_sources',
        'gaminghub_manager_packages',
        'gaminghub_manager_operations',
        'gaminghub_manager_backups',
        'gaminghub_manager_settings',
    ];

    private ?array $status = null;

    /**
     * @return array{schema_ready: bool, database_available: bool, missing_tables: list<string>}
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
        } catch (QueryException $exception) {
            if (! str_starts_with($this->sqlState($exception), '08')) {
                throw $exception;
            }

            return $this->status = [
                'schema_ready' => false,
                'database_available' => false,
                'missing_tables' => self::REQUIRED_TABLES,
            ];
        } catch (PDOException $exception) {
            $state = $this->sqlState($exception);
            if ($state !== '' && $state !== '0' && ! str_starts_with($state, '08')) {
                throw $exception;
            }

            return $this->status = [
                'schema_ready' => false,
                'database_available' => false,
                'missing_tables' => self::REQUIRED_TABLES,
            ];
        }

        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (! $this->tableExists($table)) {
                if (($this->status['database_available'] ?? true) === false) {
                    return $this->status;
                }
                $missing[] = $table;
            }
        }

        return $this->status = [
            'schema_ready' => $missing === [],
            'database_available' => true,
            'missing_tables' => $missing,
        ];
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
            if (str_starts_with($state, '08')) {
                $this->status = [
                    'schema_ready' => false,
                    'database_available' => false,
                    'missing_tables' => self::REQUIRED_TABLES,
                ];

                return false;
            }

            throw $exception;
        } catch (PDOException $exception) {
            $state = $this->sqlState($exception);
            if ($state === '' || $state === '0' || str_starts_with($state, '08')) {
                $this->status = [
                    'schema_ready' => false,
                    'database_available' => false,
                    'missing_tables' => self::REQUIRED_TABLES,
                ];

                return false;
            }

            throw $exception;
        }
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
