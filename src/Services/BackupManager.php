<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
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
        private ExtensionDependencyGuard $dependencies,
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
        $dependentSnapshot = [
            'dependents' => [],
            'disable_order' => [],
            'restore_order' => [],
        ];
        $dependentsChanged = false;
        $targetChanged = false;

        try {
            $operation->transition('validating', 'Validating backup integrity, package metadata, and dependency compatibility.');
            $backupPath = $this->path($backup);
            $actualHash = $this->hasher->hash($backupPath);
            if (! hash_equals($backup->integrity_hash, $actualHash)) {
                throw new ExtensionOperationFailed('Backup integrity verification failed.');
            }
            $manifest = $this->installed->readManifest($backupPath, $packageId);
            if ($manifest->version !== $backup->version) {
                throw new ExtensionOperationFailed('Backup version metadata does not match its files.');
            }

            // A backup restore is still a package replacement. For legacy
            // plugin.json-only packages, preserve the Manager-stored registry contract
            // before validating dependencies, exactly as installed-state reconciliation does.
            $candidateManifest = $this->manifestWithStoredContract(
                $manifest,
                $backup->manifest_snapshot,
                is_file($backupPath.'/gaming-hub-extension.json'),
            );
            $this->dependencies->assertUpdateAllowed($candidateManifest);
            $dependentSnapshot = $this->dependentSnapshot($packageId);
            $enabledDependentIds = [];
            foreach ($dependentSnapshot['dependents'] as $dependent) {
                if ($dependent['enabled']) {
                    $enabledDependentIds[] = $dependent['id'];
                }
            }
            if (! $backup->enabled_snapshot && $enabledDependentIds !== []) {
                throw new ExtensionOperationFailed(
                    'Backup restore blocked because the backup would leave a required dependency disabled while enabled packages depend on it: '
                    .implode(', ', $enabledDependentIds).'.',
                );
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
                'enabled_before' => $wasEnabled,
                'enabled_after_requested' => (bool) $backup->enabled_snapshot,
                'affected_dependents' => array_keys($dependentSnapshot['dependents']),
            ]);

            if ($hadLive && $package !== null) {
                $operation->transition('backing_up', 'Creating a recovery backup of the current package before restore.');
                $recovery = $this->createFromPath(
                    $packageId,
                    $package->installed_version,
                    $live,
                    $wasEnabled,
                    $package->manifest_snapshot,
                    $actor,
                    'pre_restore',
                    $operation->operation_uuid,
                );
                $operation->mergeContext(['recovery_backup_uuid' => $recovery->backup_uuid]);
            }

            $operation->transition('staging', 'Copying the selected backup into same-filesystem staging.');
            $incoming = $this->paths->pluginsRoot(true).'/.gaming-hub-manager-restore-incoming-'.$operation->operation_uuid;
            $this->paths->copyDirectory($backupPath, $incoming);
            if (! hash_equals($backup->integrity_hash, $this->hasher->hash($incoming))) {
                throw new ExtensionOperationFailed('Staged restore files failed integrity verification.');
            }

            $operation->transition('disabling_dependents', 'Temporarily disabling enabled dependents before package restore.');
            $dependentsChanged = $this->disableDependents($dependentSnapshot, $operation);

            $operation->transition('disabling', $hadLive ? 'Disabling the package before restore replacement.' : 'Package files are absent; no target disable action is required.');
            if ($hadLive && $wasEnabled) {
                if (! $this->lifecycle->disable($packageId) || $this->lifecycle->isEnabled($packageId)) {
                    throw new ExtensionOperationFailed('Azuriom could not disable the package before restore.');
                }
                $targetChanged = true;
            }

            $operation->transition('replacing', $hadLive
                ? 'Atomically replacing the package with the selected backup.'
                : 'Restoring the package files from the selected backup.');
            if ($hadLive) {
                $previous = $this->paths->pluginsRoot(true).'/.gaming-hub-manager-restore-previous-'.$operation->operation_uuid;
                if (! rename($live, $previous)) {
                    throw new ExtensionOperationFailed('Unable to stage the current package during restore.');
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

            // Refresh before lifecycle checks so Azuriom and Manager see the restored
            // physical plugin immediately in this same request.
            $this->lifecycle->refresh();
            $this->installed->reconcileFilesystem();
            $package = InstalledExtension::where('extension_id', $packageId)->first() ?? $package;

            $operation->transition('enabling', 'Restoring the enabled state captured by the backup.');
            if ($backup->enabled_snapshot) {
                $this->dependencies->assertManifestEnableAllowed($candidateManifest);
                if (! $this->lifecycle->enable($packageId) || ! $this->lifecycle->isEnabled($packageId)) {
                    throw new ExtensionOperationFailed('Azuriom could not restore the backed-up enabled state.');
                }
            } elseif ($this->lifecycle->isEnabled($packageId)) {
                if (! $this->lifecycle->disable($packageId) || $this->lifecycle->isEnabled($packageId)) {
                    throw new ExtensionOperationFailed('Azuriom could not restore the backed-up disabled state.');
                }
            }

            $operation->transition('restoring_dependents', 'Restoring dependent package enabled states in dependency order.');
            $this->restoreDependentStates($dependentSnapshot, $operation);

            $restoredSnapshot = $candidateManifest->toArray();
            $package = InstalledExtension::where('extension_id', $packageId)->first() ?? $package ?? new InstalledExtension();
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
                'last_operation_result' => 'restored',
            ])->save();
            $backup->forceFill(['restored_at' => now(), 'restored_by' => $actor])->save();

            $operation->transition('cleaning', 'Refreshing installed state and removing restore staging.');
            $this->lifecycle->refresh();
            $this->installed->reconcileFilesystem();
            $package = $this->installed->resolve($packageId, true, false);
            if ($previous !== null && is_dir($previous)) {
                $this->paths->deleteDirectory($previous);
                $oldMoved = false;
            }
            $operation->complete('Package files restored to '.$backup->version.' with dependency state preserved. Database data and migrations were retained.');

            return $package;
        } catch (\Throwable $exception) {
            $operation->mergeContext(['failed_stage' => $operation->current_stage ?: 'unknown']);
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
                    } else {
                        $oldMoved = false;
                    }
                } catch (\Throwable) {
                    $rollbackSucceeded = false;
                }
            }
            try {
                $this->lifecycle->refresh();
                $this->installed->reconcileFilesystem();
                if ($hadLive) {
                    if ($wasEnabled && ! $this->lifecycle->isEnabled($packageId)) {
                        $rollbackSucceeded = $this->lifecycle->enable($packageId) && $this->lifecycle->isEnabled($packageId) && $rollbackSucceeded;
                    } elseif (! $wasEnabled && $this->lifecycle->isEnabled($packageId)) {
                        $rollbackSucceeded = $this->lifecycle->disable($packageId) && ! $this->lifecycle->isEnabled($packageId) && $rollbackSucceeded;
                    }
                }
                if ($dependentsChanged) {
                    $this->restoreDependentStates($dependentSnapshot, $operation);
                }
                $this->installed->reconcileFilesystem();
            } catch (\Throwable $rollbackException) {
                $operation->mergeContext([
                    'restoration_failure_stage' => 'restore_rollback',
                    'restoration_failure_message' => $this->messages->fromThrowable($rollbackException),
                ]);
                $operation->appendEvent('rollback_failed', $this->messages->fromThrowable($rollbackException), 'error');
                $operation->save();
                $rollbackSucceeded = false;
            }
            $rollbackAttempted = $oldMoved || $newMoved || $dependentsChanged || $targetChanged;
            $operation->forceFill([
                'rollback_attempted' => $rollbackAttempted,
                'rollback_succeeded' => $rollbackAttempted ? $rollbackSucceeded : null,
            ])->save();
            $operation->fail(
                $this->messages->fromThrowable($exception),
                'manual_restore_failed',
                $rollbackAttempted ? ($rollbackSucceeded ? 'rolled_back' : 'rollback_failed') : 'failed',
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

    /**
     * @return array{dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>}
     */
    private function dependentSnapshot(string $packageId): array
    {
        return $this->dependentSnapshotFrom($this->dependencies->dependentsOf($packageId));
    }

    /**
     * @param list<array{id: string, enabled: bool, depth: int}> $dependents
     * @return array{dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>}
     */
    private function dependentSnapshotFrom(array $dependents): array
    {
        $snapshot = [];
        foreach ($dependents as $dependent) {
            $snapshot[$dependent['id']] = [
                'id' => $dependent['id'],
                'enabled' => (bool) $dependent['enabled'],
                'depth' => (int) $dependent['depth'],
            ];
        }

        $restoreOrder = array_keys($snapshot);
        usort($restoreOrder, static fn (string $left, string $right): int => [$snapshot[$left]['depth'], $left] <=> [$snapshot[$right]['depth'], $right]);
        $disableOrder = $restoreOrder;
        usort($disableOrder, static fn (string $left, string $right): int => [$snapshot[$right]['depth'], $right] <=> [$snapshot[$left]['depth'], $left]);

        return [
            'dependents' => $snapshot,
            'disable_order' => $disableOrder,
            'restore_order' => $restoreOrder,
        ];
    }

    /**
     * @param array{dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>} $snapshot
     */
    private function disableDependents(array $snapshot, ExtensionOperation $operation): bool
    {
        $changed = false;
        foreach ($snapshot['disable_order'] as $dependentId) {
            $state = $snapshot['dependents'][$dependentId];
            if (! $state['enabled'] || ! $this->lifecycle->isEnabled($dependentId)) {
                continue;
            }
            if (! $this->lifecycle->disable($dependentId) || $this->lifecycle->isEnabled($dependentId)) {
                throw new ExtensionOperationFailed('Could not disable dependent package '.$dependentId.' before backup restore.');
            }
            $changed = true;
            $operation->appendEvent('disabling_dependents', 'Temporarily disabled dependent package '.$dependentId.'.');
            $operation->save();
        }

        return $changed;
    }

    /**
     * @param array{dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>} $snapshot
     */
    private function restoreDependentStates(array $snapshot, ExtensionOperation $operation): void
    {
        foreach ($snapshot['restore_order'] as $dependentId) {
            $state = $snapshot['dependents'][$dependentId];
            if ($state['enabled']) {
                if (! $this->lifecycle->isEnabled($dependentId)) {
                    $this->dependencies->assertEnableAllowed($dependentId);
                    if (! $this->lifecycle->enable($dependentId) || ! $this->lifecycle->isEnabled($dependentId)) {
                        throw new ExtensionOperationFailed('Previously enabled dependent package '.$dependentId.' could not be restored.');
                    }
                }
                $operation->appendEvent('restoring_dependents', 'Restored dependent package '.$dependentId.'.');
                $operation->save();
            } elseif ($this->lifecycle->isEnabled($dependentId)) {
                if (! $this->lifecycle->disable($dependentId) || $this->lifecycle->isEnabled($dependentId)) {
                    throw new ExtensionOperationFailed('Previously disabled dependent package '.$dependentId.' became enabled unexpectedly.');
                }
            }
        }
    }


    private function manifestWithStoredContract(ExtensionManifest $manifest, ?array $existing, bool $hasPackageManifest): ExtensionManifest
    {
        $raw = $this->preserveRegistryContract($manifest->toArray(), $existing, $hasPackageManifest);
        if ($raw === $manifest->toArray()) {
            return $manifest;
        }

        return new ExtensionManifest(
            $manifest->schema,
            $manifest->id,
            $manifest->name,
            $manifest->version,
            is_string($raw['type'] ?? null) ? $raw['type'] : $manifest->type,
            $manifest->description,
            $manifest->author,
            is_string($raw['homepage'] ?? null) ? $raw['homepage'] : $manifest->homepage,
            is_string($raw['repository'] ?? null) ? $raw['repository'] : $manifest->repository,
            is_array($raw['requires'] ?? null) ? $raw['requires'] : $manifest->requires,
            is_array($raw['provides'] ?? null) ? $raw['provides'] : $manifest->provides,
            is_array($raw['consumes'] ?? null) ? $raw['consumes'] : $manifest->consumes,
            $manifest->pluginDirectory,
            $manifest->checksumAlgorithm,
            is_string($raw['public_attribution_label'] ?? null) ? $raw['public_attribution_label'] : $manifest->publicAttributionLabel,
            $raw,
        );
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
