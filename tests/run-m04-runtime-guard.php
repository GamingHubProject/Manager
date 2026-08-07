<?php

declare(strict_types=1);

namespace Azuriom\Plugin\GamingHubManager\Services {
    final class ManagerSchema
    {
        public const MIGRATIONS_PENDING = 'MIGRATIONS_PENDING';
        public const SCHEMA_INCONSISTENT = 'SCHEMA_INCONSISTENT';
        public const DATABASE_UNAVAILABLE = 'DATABASE_UNAVAILABLE';

        public function __construct(private array $status)
        {
        }

        public function status(bool $refresh = false): array
        {
            return $this->status;
        }
    }

    abstract class CountingStub
    {
        public int $calls = 0;

        protected function hit(): void
        {
            $this->calls++;
            throw new \RuntimeException('DB-backed preparation must not run while schema is unhealthy.');
        }
    }

    final class ManagerSettings extends CountingStub
    {
        public function applyToConfig(): void
        {
            $this->hit();
        }
    }

    final class ExtensionSourceManager extends CountingStub
    {
        public function ensureOfficial(): void
        {
            $this->hit();
        }
    }

    final class LegacyMetadataImporter extends CountingStub
    {
        public function import(): array
        {
            $this->hit();
        }
    }

    final class InstalledExtensionResolver extends CountingStub
    {
        public function reconcileFilesystem(): void
        {
            $this->hit();
        }
    }

    final class ExtensionPathGuard extends CountingStub
    {
        public function deleteDirectory(string $path): void
        {
            $this->hit();
        }
    }
}

namespace Azuriom\Plugin\GamingHubManager\Models {
    final class ExtensionOperation
    {
        public static int $queries = 0;

        public static function query(): never
        {
            self::$queries++;
            throw new \RuntimeException('gaminghub_manager_operations must not be queried while schema is unhealthy.');
        }
    }
}

namespace {
    require dirname(__DIR__).'/src/Services/ManagerRuntime.php';

    use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
    use Azuriom\Plugin\GamingHubManager\Services\ExtensionPathGuard;
    use Azuriom\Plugin\GamingHubManager\Services\ExtensionSourceManager;
    use Azuriom\Plugin\GamingHubManager\Services\InstalledExtensionResolver;
    use Azuriom\Plugin\GamingHubManager\Services\LegacyMetadataImporter;
    use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
    use Azuriom\Plugin\GamingHubManager\Services\ManagerSchema;
    use Azuriom\Plugin\GamingHubManager\Services\ManagerSettings;

    $failures = [];
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
        $checks++;
        if (! $condition) {
            $failures[] = $message;
        }
    };

    $cases = [
        'operations recorded but missing' => [
            'state' => ManagerSchema::SCHEMA_INCONSISTENT,
            'schema_state' => ManagerSchema::SCHEMA_INCONSISTENT,
            'schema_ready' => false,
            'database_available' => true,
            'migration_history_available' => true,
            'missing_tables' => ['gaminghub_manager_operations'],
            'pending_migrations' => [],
            'recorded_missing_tables' => ['gaminghub_manager_operations'],
            'unrecorded_existing_tables' => [],
            'relevant_recorded_migrations' => [],
        ],
        'normal pending schema' => [
            'state' => ManagerSchema::MIGRATIONS_PENDING,
            'schema_state' => ManagerSchema::MIGRATIONS_PENDING,
            'schema_ready' => false,
            'database_available' => true,
            'migration_history_available' => true,
            'missing_tables' => ['gaminghub_manager_settings'],
            'pending_migrations' => ['settings-migration'],
            'recorded_missing_tables' => [],
            'unrecorded_existing_tables' => [],
            'relevant_recorded_migrations' => [],
        ],
        'database unavailable' => [
            'state' => ManagerSchema::DATABASE_UNAVAILABLE,
            'schema_state' => ManagerSchema::DATABASE_UNAVAILABLE,
            'schema_ready' => false,
            'database_available' => false,
            'migration_history_available' => false,
            'missing_tables' => ['gaminghub_manager_sources', 'gaminghub_manager_packages', 'gaminghub_manager_operations', 'gaminghub_manager_backups', 'gaminghub_manager_settings'],
            'pending_migrations' => [],
            'recorded_missing_tables' => [],
            'unrecorded_existing_tables' => [],
            'relevant_recorded_migrations' => [],
        ],
    ];

    foreach ($cases as $label => $status) {
        ExtensionOperation::$queries = 0;
        $settings = new ManagerSettings();
        $sources = new ExtensionSourceManager();
        $legacy = new LegacyMetadataImporter();
        $installed = new InstalledExtensionResolver();
        $paths = new ExtensionPathGuard();
        $runtime = new ManagerRuntime(new ManagerSchema($status), $settings, $sources, $legacy, $installed, $paths);

        $summary = $runtime->prepare();
        $assert($summary['schema_state'] === $status['schema_state'], $label.': runtime must preserve schema state');
        $assert($runtime->isReady($summary) === false, $label.': runtime must remain not ready');
        $assert($settings->calls === 0, $label.': settings loading must not start');
        $assert($sources->calls === 0, $label.': official source initialization must not start');
        $assert($legacy->calls === 0, $label.': legacy import must not start');
        $assert($installed->calls === 0, $label.': filesystem/DB reconciliation must not start');
        $assert($paths->calls === 0, $label.': staging cleanup must not start');
        $assert(ExtensionOperation::$queries === 0, $label.': operation table must not be queried');
    }

    $inconsistentMessage = (new ManagerRuntime(
        new ManagerSchema($cases['operations recorded but missing']),
        new ManagerSettings(),
        new ExtensionSourceManager(),
        new LegacyMetadataImporter(),
        new InstalledExtensionResolver(),
        new ExtensionPathGuard(),
    ))->recoveryMessage($cases['operations recorded but missing']);
    $assert(str_contains($inconsistentMessage, 'disagree'), 'inconsistent recovery message must explain history/schema disagreement');
    $assert(str_contains($inconsistentMessage, 'Automatic repair was not attempted'), 'inconsistent recovery message must state no automatic repair');

    if ($failures !== []) {
        fwrite(STDERR, "FAILED: M0.4 runtime guard behavior\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, '- '.$failure."\n");
        }
        exit(1);
    }

    echo 'PASS: M0.4 runtime guard behavior ('.$checks." checks)\n";
}
