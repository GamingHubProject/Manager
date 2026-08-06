<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Azuriom\Plugin\GamingHubManager\Models\PackageBackup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class BackupManager
{
    public function __construct(
        private ExtensionPathGuard $paths,
        private InstalledExtensionResolver $installed,
        private DirectoryHasher $hasher,
        private AzuriomPluginLifecycle $lifecycle,
        private ExtensionSafeMessage $messages,
    ) {
    }

    public function create(InstalledExtension $package, int $actor, ExtensionOperation $operation, string $reason = 'manual'): PackageBackup
    {
        $packageId = $this->paths->validateId($package->extension_id);
        $lock = Cache::lock('gaminghub-manager:package-operation:'.$packageId, 300);
        if (! $lock->get()) {
            throw new ExtensionOperationFailed('Another lifecycle operation for this package is already running.');
        }

        try {
            $operation->transition('backing_up', 'Creating a verified package backup.');
            $backup = $this->createFromPath(
                $packageId,
                $package->installed_version,
                $this->paths->destination($packageId, true),
                $this->lifecycle->isEnabled($packageId),
                $package->manifest_snapshot,
                $actor,
                $reason,
                $operation->operation_uuid,
            );
            $operation->mergeContext(['backup_uuid' => $backup->backup_uuid]);
            $operation->complete('Package backup created.');

            return $backup;
        } finally {
            $lock->release();
        }
    }

    public function createFromPath(
        string $packageId,
        string $version,
        string $sourcePath,
        bool $enabled,
        array $manifest,
        ?int $actor,
        string $reason,
        ?string $operationUuid,
    ): PackageBackup {
        $uuid = (string) Str::uuid();
        $relative = now()->format('Ymd_His').'-'.$uuid.'/'.$packageId;
        $destination = $this->root().DIRECTORY_SEPARATOR.$relative;
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true) && ! is_dir(dirname($destination))) {
            throw new ExtensionOperationFailed('Unable to create the package backup directory.');
        }

        try {
            $this->paths->copyDirectory($sourcePath, $destination);
            $normalized = $this->installed->readManifest($destination, $packageId);
            $snapshot = $this->preserveRegistryContract(
                $normalized->toArray(),
                $manifest,
                is_file($destination.'/gaming-hub-extension.json'),
            );
            $hash = $this->hasher->hash($destination);

            return PackageBackup::create([
                'backup_uuid' => $uuid,
                'extension_id' => $packageId,
                'version' => $normalized->version ?: $version,
                'relative_path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
                'integrity_hash' => $hash,
                'enabled_snapshot' => $enabled,
                'manifest_snapshot' => $snapshot,
                'reason' => $reason,
                'source_operation_uuid' => $operationUuid,
                'created_by' => $actor,
            ]);
        } catch (\Throwable $exception) {
            if (is_dir(dirname($destination))) {
                try {
                    $this->paths->deleteDirectory(dirname($destination));
                } catch (\Throwable) {
                }
            }
            throw $exception;
        }
    }

    public function restore(PackageBackup $backup, int $actor, ExtensionOperation $operation): InstalledExtension
    {
        $packageId = $this->paths->validateId($backup->extension_id);
        if ($packageId === 'gaming-hub-manager') {
            throw new ExtensionOperationFailed('Gaming Hub Manager cannot restore itself.');
        }

        $lock = Cache::lock('gaminghub-manager:package-operation:'.$packageId, 300);
        if (! $lock->get()) {
            throw new ExtensionOperationFailed('Another lifecycle operation for this package is already running.');
        }

        $incoming = null;
        $previous = null;
        $live = null;
        $oldMoved = false;
        $newMoved = false;
        $wasEnabled = false;
        $hadLive = false;
        $package = null;

        try {
            $operation->transition('validating', 'Validating backup integrity and package metadata.');
            $backupPath = $this->path($backup);
            $actualHash = $this->hasher->hash($backupPath);
            if (! hash_equals($backup->integrity_hash, $actualHash)) {
                throw new ExtensionOperationFailed('Backup integrity verification failed.');
            }
            $manifest = $this->installed->readManifest($backupPath, $packageId);
            if ($manifest->version !== $backup->version) {
                throw new ExtensionOperationFailed('Backup version metadata does not match its files.');
            }

            $package = InstalledExtension::where('extension_id', $packageId)->first();
            $live = $this->paths->destination($packageId);
            $hadLive = is_dir($live);
            if ($hadLive) {
                $package = $this->installed->resolve($packageId);
                $wasEnabled = $this->lifecycle->isEnabled($packageId);
            }
            $operation->mergeContext([
                'from_version' => $package?->installed_version,
                'to_version' => $backup->version,
                'backup_uuid' => $backup->backup_uuid,
                'restoring_uninstalled_package' => ! $hadLive,
                'database_rollback' => false,
            ]);

            if ($hadLive && $package !== null) {
                $operation->transition('backing_up', 'Creating a recovery backup of the current package before rollback.');
                $recovery = $this->createFromPath(
                    $packageId,
                    $package->installed_version,
                    $live,
                    $wasEnabled,
                    $package->manifest_snapshot,
                    $actor,
                    'pre_rollback',
                    $operation->operation_uuid,
                );
                $operation->mergeContext(['recovery_backup_uuid' => $recovery->backup_uuid]);
            }

            $operation->transition('staging', 'Copying the selected backup into same-filesystem staging.');
            $incoming = $this->paths->pluginsRoot(true).'/.gaming-hub-manager-rollback-incoming-'.$operation->operation_uuid;
            $this->paths->copyDirectory($backupPath, $incoming);
            if (! hash_equals($backup->integrity_hash, $this->hasher->hash($incoming))) {
                throw new ExtensionOperationFailed('Staged rollback files failed integrity verification.');
            }

            $operation->transition('disabling', $hadLive ? 'Disabling the package before rollback replacement.' : 'Package files are absent; no disable action is required.');
            if ($hadLive && $wasEnabled && (! $this->lifecycle->disable($packageId) || $this->lifecycle->isEnabled($packageId))) {
                throw new ExtensionOperationFailed('Azuriom could not disable the package before rollback.');
            }

            $operation->transition('replacing', $hadLive
                ? 'Atomically replacing the package with the selected backup.'
                : 'Restoring the package files from the selected backup.');
            if ($hadLive) {
                $previous = $this->paths->pluginsRoot(true).'/.gaming-hub-manager-rollback-previous-'.$operation->operation_uuid;
                if (! rename($live, $previous)) {
                    throw new ExtensionOperationFailed('Unable to stage the current package during rollback.');
                }
                $oldMoved = true;
            }
            if (! rename($incoming, $live)) {
                if ($oldMoved && $previous !== null) {
                    rename($previous, $live);
                    $oldMoved = false;
                }
                throw new ExtensionOperationFailed('Unable to move the backup into the plugins directory.');
            }
            $newMoved = true;

            $operation->transition('enabling', 'Restoring the enabled state captured by the backup.');
            $this->lifecycle->refresh();
            if ($backup->enabled_snapshot) {
                if (! $this->lifecycle->enable($packageId) || ! $this->lifecycle->isEnabled($packageId)) {
                    throw new ExtensionOperationFailed('Azuriom could not restore the backed-up enabled state.');
                }
            } elseif ($this->lifecycle->isEnabled($packageId) && ! $this->lifecycle->disable($packageId)) {
                throw new ExtensionOperationFailed('Azuriom could not restore the backed-up disabled state.');
            }

            $restoredSnapshot = $this->preserveRegistryContract(
                $manifest->toArray(),
                $backup->manifest_snapshot,
                is_file($live.'/gaming-hub-extension.json'),
            );
            $package ??= new InstalledExtension();
            $package->forceFill([
                'extension_id' => $packageId,
                'installed_version' => $backup->version,
                'source_type' => $package->source_type ?? 'backup',
                'source_id' => $package->source_id ?? 'manager-backup',
                'repository_url' => $package->repository_url ?? $manifest->repository,
                'checksum_verified' => false,
                'integrity_hash' => $backup->integrity_hash,
                'integrity_status' => 'verified',
                'integrity_checked_at' => now(),
                'trust_level' => $package->trust_level ?? 'local',
                'installed_at' => $package->installed_at ?? now(),
                'installed_by' => $package->installed_by ?? $actor,
                'enabled_snapshot' => $backup->enabled_snapshot,
                'manifest_snapshot' => $restoredSnapshot,
                'last_operation_result' => 'rolled_back',
            ])->save();
            $backup->forceFill(['restored_at' => now(), 'restored_by' => $actor])->save();

            $operation->transition('cleaning', 'Clearing caches and removing rollback staging.');
            $this->lifecycle->refresh();
            if ($previous !== null && is_dir($previous)) {
                $this->paths->deleteDirectory($previous);
                $oldMoved = false;
            }
            $operation->complete('Package files rolled back to '.$backup->version.'. Database data and migrations were retained.');

            return $package->refresh();
        } catch (\Throwable $exception) {
            $rollbackSucceeded = true;
            if ($newMoved && $live !== null && is_dir($live)) {
                try {
                    $this->paths->deleteDirectory($live);
                } catch (\Throwable) {
                    $rollbackSucceeded = false;
                }
            }
            if ($oldMoved && $previous !== null && is_dir($previous) && $live !== null) {
                try {
                    if (! rename($previous, $live)) {
                        $rollbackSucceeded = false;
                    }
                } catch (\Throwable) {
                    $rollbackSucceeded = false;
                }
            }
            try {
                $this->lifecycle->refresh();
                if ($wasEnabled && ! $this->lifecycle->isEnabled($packageId)) {
                    $rollbackSucceeded = $this->lifecycle->enable($packageId) && $rollbackSucceeded;
                }
            } catch (\Throwable) {
                $rollbackSucceeded = false;
            }
            $operation->forceFill(['rollback_attempted' => $oldMoved || $newMoved, 'rollback_succeeded' => $rollbackSucceeded])->save();
            $operation->fail(
                $this->messages->fromThrowable($exception),
                'manual_rollback_failed',
                ($oldMoved || $newMoved) ? ($rollbackSucceeded ? 'rolled_back' : 'rollback_failed') : 'failed',
            );
            throw $exception;
        } finally {
            if ($incoming !== null && is_dir($incoming)) {
                try {
                    $this->paths->deleteDirectory($incoming);
                } catch (\Throwable) {
                }
            }
            $lock->release();
        }
    }


    private function preserveRegistryContract(array $normalized, ?array $existing, bool $hasPackageManifest): array
    {
        if ($hasPackageManifest || ! is_array($existing)) {
            return $normalized;
        }

        foreach (['requires', 'provides', 'consumes', 'type', 'repository', 'homepage', 'public_attribution_label'] as $key) {
            if (array_key_exists($key, $existing)) {
                $normalized[$key] = $existing[$key];
            }
        }

        return $normalized;
    }

    public function delete(PackageBackup $backup): void
    {
        $path = $this->path($backup, false);
        if ($path !== null && is_dir(dirname($path))) {
            $this->paths->deleteDirectory(dirname($path));
        }
        $backup->delete();
    }

    public function root(): string
    {
        $root = storage_path('app/gaming-hub-manager/backups');
        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new ExtensionOperationFailed('Unable to create the Manager backup directory.');
        }
        $real = realpath($root);
        if ($real === false) {
            throw new ExtensionOperationFailed('Manager backup directory is unavailable.');
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function path(PackageBackup $backup, bool $mustExist = true): ?string
    {
        $relative = str_replace('\\', '/', $backup->relative_path);
        $parts = explode('/', trim($relative, '/'));
        if ($relative === ''
            || str_starts_with($relative, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relative)
            || count($parts) < 2
            || end($parts) !== $backup->extension_id) {
            throw new ExtensionOperationFailed('Unsafe backup path rejected.');
        }
        $expected = $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! file_exists($expected)) {
            if ($mustExist) {
                throw new ExtensionOperationFailed('Backup files are missing.');
            }

            return null;
        }
        $real = realpath($expected);
        if ($real === false || ! str_starts_with($real.DIRECTORY_SEPARATOR, $this->root().DIRECTORY_SEPARATOR)) {
            throw new ExtensionOperationFailed('Backup path escaped Manager storage.');
        }

        return $real;
    }
}
