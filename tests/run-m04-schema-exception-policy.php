<?php

declare(strict_types=1);

namespace Illuminate\Database {
    class QueryException extends \PDOException
    {
    }
}

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static ?\Throwable $pdoException = null;

        public static function connection(): object
        {
            return new class {
                public function getPdo(): object
                {
                    if (DB::$pdoException !== null) {
                        throw DB::$pdoException;
                    }

                    return new \stdClass();
                }
            };
        }
    }

    final class Schema
    {
        /** @var array<string, bool> */
        public static array $tables = [];
        public static ?string $throwFor = null;
        public static ?\Throwable $exception = null;

        public static function hasTable(string $table): bool
        {
            if (self::$throwFor === $table && self::$exception !== null) {
                throw self::$exception;
            }

            return self::$tables[$table] ?? false;
        }
    }

    final class Log
    {
        public static array $warnings = [];

        public static function warning(string $message, array $context = []): void
        {
            self::$warnings[] = [$message, $context];
        }
    }
}

namespace M04Test {
    final class MigrationRepository
    {
        public bool $exists = true;
        public array $ran = [];
        public ?\Throwable $exception = null;

        public function repositoryExists(): bool
        {
            return $this->exists;
        }

        public function getRan(): array
        {
            if ($this->exception !== null) {
                throw $this->exception;
            }

            return $this->ran;
        }
    }

    final class State
    {
        public static ?MigrationRepository $repository = null;
    }
}

namespace {
    function app(string $key): object
    {
        if ($key !== 'migration.repository' || \M04Test\State::$repository === null) {
            throw new \RuntimeException('Unexpected container lookup: '.$key);
        }

        return \M04Test\State::$repository;
    }

    require dirname(__DIR__).'/src/Services/ManagerSchema.php';

    use Azuriom\Plugin\GamingHubManager\Services\ManagerSchema;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Schema;
    use M04Test\MigrationRepository;
    use M04Test\State;

    $failures = [];
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
        $checks++;
        if (! $condition) {
            $failures[] = $message;
        }
    };
    $pdo = static function (string $state, string $message): \PDOException {
        $exception = new \PDOException($message);
        $exception->errorInfo = [$state, null, $message];

        return $exception;
    };
    $reset = static function (): MigrationRepository {
        DB::$pdoException = null;
        Schema::$tables = array_fill_keys(ManagerSchema::REQUIRED_TABLES, true);
        Schema::$throwFor = null;
        Schema::$exception = null;
        Log::$warnings = [];
        $repository = new MigrationRepository();
        $repository->ran = array_values(ManagerSchema::TABLE_MIGRATIONS);
        State::$repository = $repository;

        return $repository;
    };

    $reset();
    $healthy = (new ManagerSchema())->status(true);
    $assert($healthy['schema_state'] === ManagerSchema::READY, 'real status path must classify healthy schema READY');

    $reset();
    Schema::$tables['gaminghub_manager_operations'] = false;
    $inconsistent = (new ManagerSchema())->status(true);
    $assert($inconsistent['schema_state'] === ManagerSchema::SCHEMA_INCONSISTENT, 'real status path must classify recorded missing operations table inconsistent');
    $assert($inconsistent['recorded_missing_tables'] === ['gaminghub_manager_operations'], 'real status path must report missing operations table');
    $assert(count(Log::$warnings) === 1, 'inconsistent schema must be logged through normal application logging');

    $repository = $reset();
    Schema::$tables['gaminghub_manager_operations'] = false;
    $repository->ran = array_values(array_filter(
        $repository->ran,
        static fn (string $migration): bool => $migration !== ManagerSchema::TABLE_MIGRATIONS['gaminghub_manager_operations'],
    ));
    $pending = (new ManagerSchema())->status(true);
    $assert($pending['schema_state'] === ManagerSchema::MIGRATIONS_PENDING, 'missing operations table without migration record must be pending');

    $reset();
    DB::$pdoException = $pdo('08006', 'connection failure');
    $offline = (new ManagerSchema())->status(true);
    $assert($offline['schema_state'] === ManagerSchema::DATABASE_UNAVAILABLE, 'connection SQLSTATE must become DATABASE_UNAVAILABLE');

    $reset();
    Schema::$throwFor = 'gaminghub_manager_operations';
    Schema::$exception = $pdo('42P01', 'undefined table');
    $raceSafe = (new ManagerSchema())->status(true);
    $assert($raceSafe['schema_state'] === ManagerSchema::SCHEMA_INCONSISTENT, 'PostgreSQL 42P01 during table inspection must become controlled missing-table state');

    $reset();
    Schema::$throwFor = 'gaminghub_manager_operations';
    Schema::$exception = $pdo('23505', 'unique violation from unrelated defect');
    $surfaced = false;
    try {
        (new ManagerSchema())->status(true);
    } catch (\PDOException $exception) {
        $surfaced = ($exception->errorInfo[0] ?? null) === '23505';
    }
    $assert($surfaced, 'unexpected non-schema PDO errors must surface instead of becoming migration warnings');

    $repository = $reset();
    $repository->exception = $pdo('23505', 'migration repository query defect');
    $historySurfaced = false;
    try {
        (new ManagerSchema())->status(true);
    } catch (\PDOException $exception) {
        $historySurfaced = ($exception->errorInfo[0] ?? null) === '23505';
    }
    $assert($historySurfaced, 'unexpected migration-repository query errors must surface');

    if ($failures !== []) {
        fwrite(STDERR, "FAILED: M0.4 schema exception policy\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, '- '.$failure."\n");
        }
        exit(1);
    }

    echo 'PASS: M0.4 schema exception policy ('.$checks." checks)\n";
}
