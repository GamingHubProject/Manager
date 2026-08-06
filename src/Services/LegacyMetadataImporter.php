<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Azuriom\Plugin\GamingHubManager\Models\PackageBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyMetadataImporter
{
    public function __construct(
        private ManagerSettings $settings,
        private InstalledExtensionResolver $resolver,
        private ExtensionPathGuard $paths,
        private BackupManager $backups,
        private DirectoryHasher $hasher,
        private ExtensionSafeMessage $messages,
    ) {
    }

    /**
     * @return array{
     *     sources: int,
     *     packages: int,
     *     operations: int,
     *     backups: int,
     *     warnings: list<string>,
     *     disabled?: bool,
     *     throttled?: bool,
     *     last_run?: string
     * }
     */
    public function import(): array
    {
        $summary = [
            'sources' => 0,
            'packages' => 0,
            'operations' => 0,
            'backups' => 0,
            'warnings' => [],
        ];

        if (! (bool) config('gaming-hub-manager.manager.auto_import_legacy_core_metadata', true)) {
            return $summary + ['disabled' => true];
        }

        $lastRun = $this->settings->getInternal('legacy_import_last_run');
        if (is_string($lastRun) && strtotime($lastRun) !== false && strtotime($lastRun) > now()->subHour()->getTimestamp()) {
            $lastSummary = $this->settings->getInternal('legacy_import_last_summary');
            if (is_array($lastSummary['warnings'] ?? null)) {
                foreach ($lastSummary['warnings'] as $warning) {
                    if (is_string($warning) && trim($warning) !== '') {
                        $summary['warnings'][] = $this->messages->sanitize($warning);
                    }
                }
            }

            return $summary + ['throttled' => true, 'last_run' => $lastRun];
        }

        $this->importSources($summary);
        $this->importPackages($summary);
        $this->importOperations($summary);

        try {
            $summary['backups'] = $this->importLegacyBackups();
        } catch (\Throwable $exception) {
            $this->warn($summary, 'Backup import', $exception);
        }

        try {
            $this->resolver->reconcileFilesystem();
        } catch (\Throwable $exception) {
            $this->warn($summary, 'Filesystem reconciliation', $exception);
        }

        $this->settings->putInternal('legacy_import_last_run', now()->toIso8601String());
        $this->settings->putInternal('legacy_import_last_summary', $summary);

        return $summary;
    }

    /**
     * @param array{sources: int, packages: int, operations: int, backups: int, warnings: list<string>} $summary
     */
    private function importSources(array &$summary): void
    {
        try {
            if (! Schema::hasTable('gaminghub_extension_sources')) {
                return;
            }

            foreach (DB::table('gaminghub_extension_sources')->orderBy('id')->get() as $row) {
                $values = $this->row($row);
                $recordLabel = 'Source record '.($values['id'] ?? 'unknown');

                try {
                    $sourceId = $this->requiredString($values, 'source_id', 100);
                    if (ExtensionSource::where('source_id', $sourceId)->exists()) {
                        continue;
                    }

                    $type = $this->string($values['type'] ?? null, 'registry', 30);
                    $official = $type === 'official';
                    ExtensionSource::create([
                        'source_id' => $sourceId,
                        'type' => $official ? 'registry' : $type,
                        'name' => $this->requiredString($values, 'name', 150).' (imported from Core)',
                        'url' => $this->requiredString($values, 'url', 2048),
                        'trust_level' => $official ? 'trusted' : $this->string($values['trust_level'] ?? null, 'untrusted', 30),
                        'trusted' => $official || (bool) ($values['trusted'] ?? false),
                        'enabled' => (bool) ($values['enabled'] ?? false),
                        'allow_prereleases' => (bool) ($values['allow_prereleases'] ?? false),
                        'allow_private_host' => (bool) ($values['allow_private_host'] ?? false),
                        'added_by' => $values['added_by'] ?? null,
                        'last_successful_refresh_at' => $values['last_successful_refresh_at'] ?? null,
                        'last_error' => is_string($values['last_error'] ?? null)
                            ? $this->messages->sanitize($values['last_error'])
                            : null,
                        'metadata' => $this->decode($values['metadata'] ?? null),
                    ]);
                    $summary['sources']++;
                } catch (\Throwable $exception) {
                    $this->warn($summary, $recordLabel, $exception);
                }
            }
        } catch (\Throwable $exception) {
            $this->warn($summary, 'Source import', $exception);
        }
    }

    /**
     * @param array{sources: int, packages: int, operations: int, backups: int, warnings: list<string>} $summary
     */
    private function importPackages(array &$summary): void
    {
        try {
            if (! Schema::hasTable('gaminghub_installed_extensions')) {
                return;
            }

            foreach (DB::table('gaminghub_installed_extensions')->orderBy('id')->get() as $row) {
                $values = $this->row($row);
                $recordLabel = 'Package record '.($values['id'] ?? 'unknown');

                try {
                    $extensionId = $this->requiredPackageId($values, 'extension_id');
                    if (InstalledExtension::where('extension_id', $extensionId)->exists()) {
                        continue;
                    }
                    if (! is_dir($this->paths->destination($extensionId))) {
                        throw new RuntimeException('Installed package directory is missing; stale legacy record skipped.');
                    }

                    InstalledExtension::create([
                        'extension_id' => $extensionId,
                        'installed_version' => $this->requiredString($values, 'installed_version', 100),
                        'source_type' => $this->string($values['source_type'] ?? null, 'legacy', 30),
                        'source_id' => $values['source_id'] ?? null,
                        'source_url' => $values['source_url'] ?? null,
                        'repository_url' => $values['repository_url'] ?? null,
                        'release_url' => $values['release_url'] ?? null,
                        'release_id' => $values['release_id'] ?? null,
                        'asset_name' => $values['asset_name'] ?? null,
                        'checksum' => $values['checksum'] ?? null,
                        'checksum_verified' => (bool) ($values['checksum_verified'] ?? false),
                        'integrity_status' => 'unknown',
                        'trust_level' => $this->string($values['trust_level'] ?? null, 'legacy', 30),
                        'installed_by' => $values['installed_by'] ?? null,
                        'installed_at' => $values['installed_at'] ?? null,
                        'enabled_snapshot' => (bool) ($values['enabled_snapshot'] ?? false),
                        'manifest_snapshot' => $this->decode($values['manifest_snapshot'] ?? null) ?? [],
                        'last_operation_result' => 'imported',
                    ]);
                    $summary['packages']++;
                } catch (\Throwable $exception) {
                    $this->warn($summary, $recordLabel, $exception);
                }
            }
        } catch (\Throwable $exception) {
            $this->warn($summary, 'Package import', $exception);
        }
    }

    /**
     * @param array{sources: int, packages: int, operations: int, backups: int, warnings: list<string>} $summary
     */
    private function importOperations(array &$summary): void
    {
        try {
            if (! Schema::hasTable('gaminghub_extension_operations')) {
                return;
            }

            foreach (DB::table('gaminghub_extension_operations')->orderByDesc('id')->limit(1000)->get() as $row) {
                $values = $this->row($row);
                $recordLabel = 'Operation record '.($values['id'] ?? 'unknown');

                try {
                    $operationUuid = $this->requiredString($values, 'operation_uuid', 100);
                    if (ExtensionOperation::where('operation_uuid', $operationUuid)->exists()) {
                        continue;
                    }

                    $result = $this->string($values['result'] ?? null, 'failed', 30);
                    ExtensionOperation::create([
                        'operation_uuid' => $operationUuid,
                        'operation' => $this->requiredString($values, 'operation', 50),
                        'extension_id' => $values['extension_id'] ?? null,
                        'version' => $values['version'] ?? null,
                        'source_id' => $values['source_id'] ?? null,
                        'actor_id' => $values['actor_id'] ?? null,
                        'started_at' => $values['started_at'] ?? now(),
                        'finished_at' => $values['finished_at'] ?? null,
                        'result' => $result,
                        'current_stage' => $values['current_stage'] ?? ($result === 'running' ? 'interrupted' : $result),
                        'error_category' => $values['error_category'] ?? null,
                        'summary' => '[Imported from Gaming Hub Core] '.$this->messages->sanitize((string) ($values['summary'] ?? '')),
                        'rollback_attempted' => (bool) ($values['rollback_attempted'] ?? false),
                        'rollback_succeeded' => $values['rollback_succeeded'] ?? null,
                        'context' => $this->decode($values['context'] ?? null),
                        'events' => $this->decode($values['events'] ?? null) ?? [],
                    ]);
                    $summary['operations']++;
                } catch (\Throwable $exception) {
                    $this->warn($summary, $recordLabel, $exception);
                }
            }
        } catch (\Throwable $exception) {
            $this->warn($summary, 'Operation import', $exception);
        }
    }

    private function importLegacyBackups(): int
    {
        $root = storage_path('app/gaming-hub/extensions/backups');
        if (! is_dir($root)) {
            return 0;
        }

        $count = 0;
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $operationDirectory) {
            foreach (glob($operationDirectory.'/*', GLOB_ONLYDIR) ?: [] as $packagePath) {
                $packageId = basename($packagePath);
                try {
                    $manifest = $this->resolver->readManifest($packagePath, $packageId);
                    $hash = $this->hasher->hash($packagePath);
                    if (PackageBackup::where('extension_id', $packageId)->where('integrity_hash', $hash)->exists()) {
                        continue;
                    }
                    $this->backups->createFromPath(
                        $packageId,
                        $manifest->version,
                        $packagePath,
                        false,
                        $manifest->toArray(),
                        null,
                        'legacy_import',
                        null,
                    );
                    $count++;
                } catch (\Throwable) {
                    // Invalid, stale, or unrelated backup directories are skipped.
                }
            }
        }

        return $count;
    }

    /**
     * @param array{sources: int, packages: int, operations: int, backups: int, warnings: list<string>} $summary
     */
    private function warn(array &$summary, string $label, \Throwable|string $reason): void
    {
        $message = $reason instanceof \Throwable
            ? $this->messages->fromThrowable($reason)
            : $this->messages->sanitize($reason);
        $summary['warnings'][] = $this->messages->sanitize($label.': '.$message);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object|array $row): array
    {
        return is_array($row) ? $row : get_object_vars($row);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function requiredString(array $values, string $key, int $max): string
    {
        $value = $values[$key] ?? null;
        if (! is_scalar($value)) {
            throw new RuntimeException('Missing or invalid '.$key.'.');
        }

        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new RuntimeException('Missing or invalid '.$key.'.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function requiredPackageId(array $values, string $key): string
    {
        $value = $this->requiredString($values, $key, 100);
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,98}[a-z0-9]$/', $value)) {
            throw new RuntimeException('Invalid package identifier.');
        }

        return $value;
    }

    private function string(mixed $value, string $default, int $max): string
    {
        if (! is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : mb_substr($value, 0, $max);
    }

    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
