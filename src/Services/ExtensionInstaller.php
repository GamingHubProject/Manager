<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionAlreadyCurrent;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Azuriom\Plugin\GamingHubManager\Models\PackageBackup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ExtensionInstaller
{
    public function __construct(
        private SafeExtensionHttpClient $http,
        private ExtensionArchiveInspector $archives,
        private ExtensionChecksumVerifier $checksums,
        private ExtensionCompatibility $compatibility,
        private ExtensionDependencyGuard $dependencies,
        private ExtensionVersionPolicy $versions,
        private ExtensionPathGuard $paths,
        private InstalledExtensionResolver $installed,
        private AzuriomPluginLifecycle $lifecycle,
        private ExtensionSafeMessage $messages,
        private DirectoryHasher $hasher,
        private ReleaseVersionValidator $releaseVersions,
    ) {
    }

    public function install(
        ExtensionSource $source,
        array $release,
        array $asset,
        ?string $expectedChecksum,
        int $actor,
        bool $enable = false,
        ?string $expectedExtensionId = null,
        ?array $packageMetadata = null,
        ?ExtensionOperation $operation = null,
        ?string $checksumSource = null,
    ): InstalledExtension {
        $operation ??= $this->newOperation('install', $source, $actor, $expectedExtensionId);
        $lockId = $expectedExtensionId ?? (string) ($asset['name'] ?? 'package');
        $lock = Cache::lock('gaminghub-manager:package-operation:'.$lockId, 300);
        $locked = false;
        $work = $this->workDirectory($operation);
        $incomingSwap = null;
        $live = null;
        $moved = false;
        $record = null;

        try {
            if (! $lock->get()) {
                throw new ExtensionOperationFailed('Another lifecycle operation for this extension is already running.');
            }
            $locked = true;

            // Filesystem state is authoritative. Reconciliation removes stale DB rows
            // before deciding whether the requested package is already installed.
            $this->installed->reconcileFilesystem();
            if ($expectedExtensionId !== null && $expectedExtensionId !== 'direct') {
                $expectedExtensionId = $this->paths->validateId($expectedExtensionId);
                if (InstalledExtension::where('extension_id', $expectedExtensionId)->exists()
                    || is_dir($this->paths->destination($expectedExtensionId))) {
                    throw new ExtensionOperationFailed('This extension is already installed; use Update instead.');
                }
            }

            [$manifest, $actualChecksum, $staged] = $this->preparePackage(
                $operation,
                $source,
                $release,
                $asset,
                $expectedChecksum,
                $work,
                $packageMetadata,
                $checksumSource,
            );

            if ($expectedExtensionId !== null && $expectedExtensionId !== 'direct' && $manifest->id !== $expectedExtensionId) {
                throw new ExtensionOperationFailed('Package identity mismatch: downloaded manifest ID does not match the selected extension.');
            }

            // preparePackage() reconciles immediately before dependency evaluation.
            if (InstalledExtension::where('extension_id', $manifest->id)->exists()) {
                throw new ExtensionOperationFailed('This extension is already installed; use Update instead.');
            }

            $live = $this->paths->destination($manifest->id);
            if (file_exists($live)) {
                throw new ExtensionOperationFailed('This extension directory already exists; use Update after metadata reconciliation.');
            }

            $operation->transition('staging', 'Copying the validated package to same-filesystem staging.');
            $incomingSwap = $this->incomingSwapPath($operation);
            $this->paths->copyDirectory($staged, $incomingSwap);
            $this->verifyPreparedDirectory($incomingSwap, $manifest);

            $operation->transition('replacing', 'Moving the validated extension into the plugins directory.');
            if (! rename($incomingSwap, $live)) {
                throw new ExtensionOperationFailed('Atomic extension directory move failed.');
            }
            $moved = true;

            $operation->transition('migrating', 'Running extension migrations.');
            $this->lifecycle->refresh();
            $this->lifecycle->migrate($manifest->id);

            $enabled = false;
            $operation->transition('enabling', $enable
                ? 'Enabling the extension through the Azuriom lifecycle.'
                : 'Leaving the extension disabled as requested.');
            if ($enable) {
                // Re-evaluate from the installed filesystem after the atomic move. This
                // validates both Gaming Hub dependencies and Azuriom's native enabled
                // dependency semantics in the same runtime before enabling.
                $this->dependencies->assertManifestEnableAllowed($manifest);
                if (! $this->lifecycle->enable($manifest->id) || ! $this->lifecycle->isEnabled($manifest->id)) {
                    throw new ExtensionOperationFailed('Azuriom could not enable the newly installed extension.');
                }
                $enabled = true;
            }

            $record = InstalledExtension::updateOrCreate(
                ['extension_id' => $manifest->id],
                $this->metadataValues(
                    $source,
                    $release,
                    $asset,
                    $manifest,
                    $actualChecksum,
                    $expectedChecksum !== null,
                    $actor,
                    $enabled,
                    null,
                    $this->hasher->hash($live),
                ),
            );

            $operation->transition('cleaning', 'Refreshing installed state and clearing plugin/application caches.');
            $this->lifecycle->refresh();
            $this->installed->reconcileFilesystem();
            $record = $this->installed->resolve($manifest->id, true, false);
            $this->cleanupNonFatal($work, $operation);
            $operation->complete('Package installed successfully.');

            return $record;
        } catch (\Throwable $exception) {
            $operation->mergeContext(['failed_stage' => $operation->current_stage ?: 'unknown']);
            $rollbackAttempted = $moved || $record !== null;
            $rollbackSucceeded = true;

            if ($rollbackAttempted) {
                $operation->transition('rolling_back', 'Removing the partial installation.');
                try {
                    if ($live !== null && is_dir($live) && $this->lifecycle->isEnabled($operation->extension_id ?? '')) {
                        $this->lifecycle->disable((string) $operation->extension_id);
                    }
                    if ($live !== null && is_dir($live) && $operation->extension_id !== null) {
                        $this->paths->deleteExtension($operation->extension_id);
                    }
                    $record?->delete();
                    $this->lifecycle->refresh();
                    $this->installed->reconcileFilesystem();
                } catch (\Throwable) {
                    $rollbackSucceeded = false;
                }
            }

            $operation->forceFill([
                'rollback_attempted' => $rollbackAttempted,
                'rollback_succeeded' => $rollbackAttempted ? $rollbackSucceeded : null,
            ])->save();
            $operation->fail(
                $this->messages->fromThrowable($exception),
                'installation_failed',
                $rollbackAttempted ? ($rollbackSucceeded ? 'rolled_back' : 'rollback_failed') : 'failed',
            );

            throw $exception;
        } finally {
            if ($locked) {
                $lock->release();
            }
            $this->cleanupQuietly($work);
            if ($incomingSwap !== null) {
                $this->cleanupQuietly($incomingSwap);
            }
        }
    }

    public function update(
        InstalledExtension $installed,
        ExtensionSource $source,
        array $release,
        array $asset,
        ?string $expectedChecksum,
        int $actor,
        ?array $packageMetadata = null,
        bool $allowSameVersion = false,
        ?ExtensionOperation $operation = null,
        ?string $checksumSource = null,
    ): InstalledExtension {
        $extensionId = $this->paths->validateId($installed->extension_id);
        $operation ??= $this->newOperation($allowSameVersion ? 'reinstall' : 'update', $source, $actor, $extensionId);
        $lock = Cache::lock('gaminghub-manager:package-operation:'.$extensionId, 300);
        $locked = false;
        $work = $this->workDirectory($operation);
        $incomingSwap = null;
        $previousSwap = null;
        $backupPath = null;
        $backupComplete = false;
        $backupRecord = null;
        $live = null;
        $wasEnabled = false;
        $disabled = false;
        $dependentsDisabled = false;
        $oldMoved = false;
        $newMoved = false;
        $metadataWritten = false;
        $metadata = $installed->getAttributes();
        $currentManifest = null;
        $currentVersion = $installed->installed_version;
        $enabledSnapshot = null;

        try {
            if (! $lock->get()) {
                throw new ExtensionOperationFailed('Another lifecycle operation for this extension is already running.');
            }
            $locked = true;

            $operation->transition('resolving', 'Resolving the installed extension and its dependency graph.');
            $this->installed->reconcileFilesystem();
            $installed = $this->installed->resolve($extensionId, true, false);
            $metadata = $installed->getAttributes();
            $live = $this->paths->destination($extensionId, true);
            $currentManifest = $this->installed->readManifest($live, $extensionId);
            $currentVersion = $currentManifest->version;
            $enabledSnapshot = $this->dependencies->enabledStateSnapshot($extensionId);
            $wasEnabled = $enabledSnapshot['target']['enabled'];
            $enabledDependents = array_values(array_filter(
                $enabledSnapshot['dependents'],
                static fn (array $dependent): bool => $dependent['enabled'],
            ));

            $operation->mergeContext([
                'installed_version' => $currentVersion,
                'metadata_version_before' => $metadata['installed_version'] ?? null,
                'enabled_before' => $wasEnabled,
                'affected_dependents' => array_keys($enabledSnapshot['dependents']),
                'enabled_dependents_before' => array_column($enabledDependents, 'id'),
            ]);

            [$manifest, $actualChecksum, $staged] = $this->preparePackage(
                $operation,
                $source,
                $release,
                $asset,
                $expectedChecksum,
                $work,
                $packageMetadata,
                $checksumSource,
            );

            if ($manifest->id !== $extensionId || $manifest->pluginDirectory !== $extensionId) {
                throw new ExtensionOperationFailed('Package identity mismatch: update manifest ID does not match the installed extension.');
            }
            $comparison = $this->versions->compare($manifest->version, $currentVersion);
            if ($comparison < 0) {
                throw new ExtensionOperationFailed('Package downgrades require a verified backup rollback.');
            }
            if ($comparison === 0 && ! $allowSameVersion) {
                throw new ExtensionAlreadyCurrent('The selected package version is already installed.');
            }
            $this->dependencies->assertUpdateAllowed($manifest);

            $operation->transition('staging', 'Copying the validated update to same-filesystem staging.');
            $incomingSwap = $this->incomingSwapPath($operation);
            $this->paths->copyDirectory($staged, $incomingSwap);
            $this->verifyPreparedDirectory($incomingSwap, $manifest);

            $operation->transition('backing_up', 'Creating a timestamped backup of the installed extension.');
            $backupPath = $this->backupPath($operation, $extensionId);
            $this->paths->copyDirectory($live, $backupPath);
            $this->verifyPreparedDirectory($backupPath, $currentManifest);
            $relativeBackup = basename(dirname($backupPath)).'/'.$extensionId;
            $backupRecord = PackageBackup::create([
                'backup_uuid' => (string) Str::uuid(),
                'extension_id' => $extensionId,
                'version' => $currentVersion,
                'relative_path' => $relativeBackup,
                'integrity_hash' => $this->hasher->hash($backupPath),
                'enabled_snapshot' => $wasEnabled,
                'manifest_snapshot' => $installed->manifest_snapshot ?: $currentManifest->toArray(),
                'reason' => $allowSameVersion ? 'reinstall' : 'update',
                'source_operation_uuid' => $operation->operation_uuid,
                'created_by' => $actor,
            ]);
            $backupComplete = true;
            $operation->mergeContext(['backup' => $relativeBackup, 'backup_uuid' => $backupRecord->backup_uuid]);

            $operation->transition('disabling_dependents', 'Disabling enabled dependents in reverse dependency order.');
            $dependentsDisabled = $this->disableDependents($enabledSnapshot, $operation);

            $operation->transition('disabling', $wasEnabled
                ? 'Disabling the extension before replacement.'
                : 'Extension was already disabled; preserving that state.');
            if ($wasEnabled && $this->lifecycle->isEnabled($extensionId)) {
                $disableResult = $this->lifecycle->disable($extensionId);
                $disabled = ! $this->lifecycle->isEnabled($extensionId);
                if (! $disableResult || ! $disabled) {
                    throw new ExtensionOperationFailed('Azuriom could not disable the extension before update.');
                }
            }

            $operation->transition('replacing', 'Atomically replacing the installed extension directory.');
            $previousSwap = $this->previousSwapPath($operation);
            if (! rename($live, $previousSwap)) {
                throw new ExtensionOperationFailed('Unable to move the current extension into replacement staging.');
            }
            $oldMoved = true;

            if (! rename($incomingSwap, $live)) {
                if (rename($previousSwap, $live)) {
                    $oldMoved = false;
                }
                throw new ExtensionOperationFailed('Atomic update directory move failed.');
            }
            $newMoved = true;

            $operation->transition('migrating', 'Running migrations for the updated extension.');
            $this->lifecycle->refresh();
            $this->lifecycle->migrate($extensionId);

            $operation->transition('restoring_target', $wasEnabled
                ? 'Restoring the previously enabled target package.'
                : 'Keeping the target package disabled.');
            $this->restoreTargetState($enabledSnapshot, $operation, $manifest);

            $operation->transition('restoring_dependents', 'Restoring previously enabled dependents in dependency order.');
            $this->restoreDependentStates($enabledSnapshot, $operation);

            $installed->forceFill($this->metadataValues(
                $source,
                $release,
                $asset,
                $manifest,
                $actualChecksum,
                $expectedChecksum !== null,
                $actor,
                $wasEnabled,
                $installed->installed_at,
                $this->hasher->hash($live),
            ))->save();
            $metadataWritten = true;

            $operation->transition('cleaning', 'Refreshing installed state and finalizing replacement data.');
            $this->lifecycle->refresh();
            $this->installed->reconcileFilesystem();
            $installed = $this->installed->resolve($extensionId, true, false);
            if ($previousSwap !== null && is_dir($previousSwap)) {
                $this->paths->deleteDirectory($previousSwap);
                $oldMoved = false;
            }
            $this->cleanupNonFatal($work, $operation);
            if (! (bool) config('gaming-hub-manager.manager.retain_successful_update_backups', true) && $backupPath !== null) {
                $this->cleanupNonFatal(dirname($backupPath), $operation);
                $backupRecord?->delete();
            }

            $operation->complete($allowSameVersion
                ? 'Package '.$extensionId.' reinstalled at '.$manifest->version.' with dependent state restored.'
                : 'Package updated from '.$currentVersion.' to '.$manifest->version.' with dependent state restored.');

            return $installed;
        } catch (ExtensionAlreadyCurrent $exception) {
            $operation->transition('cleaning', 'No replacement was required.');
            if ($currentManifest !== null && $installed->installed_version !== $currentVersion) {
                $installed->forceFill([
                    'installed_version' => $currentVersion,
                    'manifest_snapshot' => $currentManifest->toArray(),
                    'enabled_snapshot' => $wasEnabled,
                ])->save();
            }
            $this->cleanupNonFatal($work, $operation);
            $operation->complete('Package is already up to date.');

            return $installed->refresh();
        } catch (\Throwable $exception) {
            $failedStage = $operation->current_stage ?: 'unknown';
            $operation->mergeContext(['failed_stage' => $failedStage]);
            $rollbackNeeded = $disabled || $dependentsDisabled || $oldMoved || $newMoved || $metadataWritten;
            $rollbackSucceeded = null;

            if ($rollbackNeeded && $enabledSnapshot !== null) {
                $rollbackSucceeded = $this->rollbackUpdate(
                    $operation,
                    $installed,
                    $metadata,
                    $extensionId,
                    $enabledSnapshot,
                    $live,
                    $previousSwap,
                    $backupPath,
                );
            }

            $operation->forceFill([
                'rollback_attempted' => $rollbackNeeded,
                'rollback_succeeded' => $rollbackNeeded ? $rollbackSucceeded : null,
            ])->save();
            $operation->fail(
                $this->messages->fromThrowable($exception),
                'update_failed',
                $rollbackNeeded ? ($rollbackSucceeded ? 'rolled_back' : 'rollback_failed') : 'failed',
            );

            throw $exception;
        } finally {
            if ($locked) {
                $lock->release();
            }
            $this->cleanupQuietly($work);
            if ($incomingSwap !== null) {
                $this->cleanupQuietly($incomingSwap);
            }
            if ($backupPath !== null && ! $backupComplete) {
                $this->cleanupQuietly(dirname($backupPath));
            }
        }
    }

    /**
     * @return array{0: ExtensionManifest, 1: string, 2: string}
     */
    private function preparePackage(
        ExtensionOperation $operation,
        ExtensionSource $source,
        array $release,
        array $asset,
        ?string $expectedChecksum,
        string $work,
        ?array $packageMetadata = null,
        ?string $checksumSource = null,
    ): array {
        if (! is_dir($work) && ! mkdir($work, 0755, true) && ! is_dir($work)) {
            throw new ExtensionOperationFailed('Unable to create the package operation staging directory.');
        }

        $operation->transition('downloading', 'Downloading the selected HTTPS release package.');
        $zip = $work.'/package.zip';
        $url = (string) ($asset['browser_download_url'] ?? $asset['url'] ?? '');
        $this->http->download($url, $zip, $source->allow_private_host);

        $operation->transition('validating', 'Validating checksum, archive paths, and manifests.');
        $actualChecksum = $this->checksums->verify($zip, $expectedChecksum);
        $extract = $work.'/extract';
        $manifest = $this->archives->inspect($zip, $extract, $packageMetadata);
        $this->releaseVersions->assertConsistent($release, $asset, $manifest, $packageMetadata);
        $operation->forceFill([
            'extension_id' => $manifest->id,
            'version' => $manifest->version,
        ])->save();

        // Every dependency decision starts from a freshly reconciled filesystem view.
        $this->installed->reconcileFilesystem();
        $this->compatibility->assertCompatible(
            $manifest,
            $this->azuriomVersion(),
            PHP_VERSION,
        );
        $this->dependencies->assertCandidateDependencies($manifest);
        $staged = $extract.'/'.$manifest->pluginDirectory;
        $this->paths->assertStagedDirectory($staged, $extract, $manifest->id);
        $operation->appendEvent(
            'validating',
            'SHA-256 checksum, GitHub release version, asset filename, package identity, and manifests verified'
                .($checksumSource !== null ? ' using '.$checksumSource : '').'.',
        );
        $operation->save();

        return [$manifest, $actualChecksum, $staged];
    }

    /**
     * @param array{target: array{id: string, enabled: bool}, dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>} $snapshot
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
                $operation->mergeContext([
                    'restoration_failure_plugin' => $dependentId,
                    'restoration_failure_stage' => 'dependent_disable',
                ]);
                throw new ExtensionOperationFailed('Could not disable dependent package '.$dependentId.' before replacement.');
            }
            $changed = true;
            $operation->appendEvent('disabling_dependents', 'Temporarily disabled dependent package '.$dependentId.'.');
            $operation->save();
        }

        return $changed;
    }

    /**
     * @param array{target: array{id: string, enabled: bool}, dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>} $snapshot
     */
    private function restoreTargetState(array $snapshot, ExtensionOperation $operation, ?ExtensionManifest $candidate = null): void
    {
        $extensionId = $snapshot['target']['id'];
        $shouldBeEnabled = $snapshot['target']['enabled'];

        if ($shouldBeEnabled && ! $this->lifecycle->isEnabled($extensionId)) {
            if ($candidate !== null) {
                $this->dependencies->assertManifestEnableAllowed($candidate);
            } else {
                $this->dependencies->assertEnableAllowed($extensionId);
            }
            if (! $this->lifecycle->enable($extensionId) || ! $this->lifecycle->isEnabled($extensionId)) {
                $this->recordRestorationFailure($operation, $extensionId, 'target_restoration');
                throw new ExtensionOperationFailed('Previously enabled target package '.$extensionId.' could not be restored.');
            }
        } elseif (! $shouldBeEnabled && $this->lifecycle->isEnabled($extensionId)) {
            if (! $this->lifecycle->disable($extensionId) || $this->lifecycle->isEnabled($extensionId)) {
                $this->recordRestorationFailure($operation, $extensionId, 'target_restoration');
                throw new ExtensionOperationFailed('Previously disabled target package '.$extensionId.' did not remain disabled.');
            }
        }
    }

    /**
     * @param array{target: array{id: string, enabled: bool}, dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>} $snapshot
     */
    private function restoreDependentStates(array $snapshot, ExtensionOperation $operation): void
    {
        foreach ($snapshot['restore_order'] as $dependentId) {
            $state = $snapshot['dependents'][$dependentId];
            if ($state['enabled']) {
                if (! $this->lifecycle->isEnabled($dependentId)) {
                    try {
                        $this->dependencies->assertEnableAllowed($dependentId);
                        if (! $this->lifecycle->enable($dependentId) || ! $this->lifecycle->isEnabled($dependentId)) {
                            throw new ExtensionOperationFailed('Azuriom rejected the enable operation.');
                        }
                    } catch (\Throwable $exception) {
                        $this->recordRestorationFailure($operation, $dependentId, 'dependent_restoration');
                        throw new ExtensionOperationFailed(
                            'Previously enabled dependent package '.$dependentId.' could not be restored: '.$this->messages->fromThrowable($exception),
                        );
                    }
                }
                $operation->appendEvent('restoring_dependents', 'Restored dependent package '.$dependentId.'.');
                $operation->save();
            } elseif ($this->lifecycle->isEnabled($dependentId)) {
                if (! $this->lifecycle->disable($dependentId) || $this->lifecycle->isEnabled($dependentId)) {
                    $this->recordRestorationFailure($operation, $dependentId, 'dependent_disabled_state');
                    throw new ExtensionOperationFailed('Previously disabled dependent package '.$dependentId.' became enabled unexpectedly.');
                }
            }
        }
    }

    private function recordRestorationFailure(ExtensionOperation $operation, string $pluginId, string $stage): void
    {
        $operation->mergeContext([
            'restoration_failure_plugin' => $pluginId,
            'restoration_failure_stage' => $stage,
        ]);
        $operation->appendEvent($stage, 'Enabled-state restoration failed for '.$pluginId.'.', 'error');
        $operation->save();
    }

    /**
     * @param array{target: array{id: string, enabled: bool}, dependents: array<string, array{id: string, enabled: bool, depth: int}>, disable_order: list<string>, restore_order: list<string>} $snapshot
     */
    private function rollbackUpdate(
        ExtensionOperation $operation,
        InstalledExtension $installed,
        array $metadata,
        string $extensionId,
        array $snapshot,
        ?string $live,
        ?string $previousSwap,
        ?string $backupPath,
    ): bool {
        $operation->transition('rolling_back', 'Restoring previous extension files, metadata, and enabled dependency state.');

        try {
            // Quiesce the complete affected graph before replacing the target files.
            foreach ($snapshot['disable_order'] as $dependentId) {
                if ($this->lifecycle->isEnabled($dependentId)) {
                    $this->lifecycle->disable($dependentId);
                }
            }
            if ($this->lifecycle->isEnabled($extensionId)) {
                $this->lifecycle->disable($extensionId);
            }

            if ($live !== null && is_dir($live)) {
                $this->paths->deleteExtension($extensionId);
            }

            if ($previousSwap !== null && is_dir($previousSwap)) {
                if (! rename($previousSwap, (string) $live)) {
                    throw new ExtensionOperationFailed('Unable to restore the previous extension directory.');
                }
            } elseif ($backupPath !== null && is_dir($backupPath)) {
                $this->paths->copyDirectory($backupPath, (string) $live);
            } else {
                throw new ExtensionOperationFailed('No valid previous extension backup was available.');
            }

            $restored = InstalledExtension::find($metadata['id'] ?? null) ?? $installed;
            $restored->forceFill($metadata);
            $restored->exists = true;
            $restored->save();

            $this->lifecycle->refresh();
            $this->restoreTargetState($snapshot, $operation);
            $this->restoreDependentStates($snapshot, $operation);
            $this->installed->reconcileFilesystem();

            return true;
        } catch (\Throwable $rollbackException) {
            $operation->appendEvent('rollback_failed', $this->messages->fromThrowable($rollbackException), 'error');
            $operation->save();

            return false;
        }
    }

    private function metadataValues(
        ExtensionSource $source,
        array $release,
        array $asset,
        ExtensionManifest $manifest,
        string $actualChecksum,
        bool $checksumVerified,
        int $actor,
        bool $enabled,
        mixed $installedAt,
        string $integrityHash,
    ): array {
        return [
            'extension_id' => $manifest->id,
            'installed_version' => $manifest->version,
            'source_type' => $source->type,
            'source_id' => $source->source_id,
            'source_url' => $source->url,
            'repository_url' => $manifest->repository,
            'release_url' => $release['html_url'] ?? null,
            'release_id' => (string) ($release['id'] ?? ''),
            'asset_name' => (string) ($asset['name'] ?? 'unknown'),
            'checksum' => $actualChecksum,
            'checksum_verified' => $checksumVerified,
            'integrity_hash' => $integrityHash,
            'integrity_status' => 'verified',
            'integrity_checked_at' => now(),
            'trust_level' => $source->trust_level,
            'installed_by' => $actor,
            'installed_at' => $installedAt ?? now(),
            'enabled_snapshot' => $enabled,
            'manifest_snapshot' => $manifest->toArray(),
            'last_operation_result' => 'completed',
        ];
    }

    private function verifyPreparedDirectory(string $path, ExtensionManifest $manifest): void
    {
        if (! is_file($path.'/plugin.json')) {
            throw new ExtensionOperationFailed('Prepared package directory is missing plugin.json.');
        }

        $prepared = $this->installed->readManifest($path, $manifest->id);
        if ($prepared->id !== $manifest->id || $prepared->version !== $manifest->version) {
            throw new ExtensionOperationFailed('Prepared package metadata changed after validation.');
        }
    }

    private function newOperation(
        string $type,
        ExtensionSource $source,
        int $actor,
        ?string $extensionId,
    ): ExtensionOperation {
        return ExtensionOperation::create([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation' => $type,
            'extension_id' => $extensionId === 'direct' ? null : $extensionId,
            'source_id' => $source->source_id,
            'actor_id' => $actor,
            'started_at' => now(),
            'result' => 'running',
            'current_stage' => 'resolving',
            'events' => [[
                'at' => now()->toIso8601String(),
                'stage' => 'resolving',
                'level' => 'info',
                'message' => ucfirst($type).' operation started.',
            ]],
        ]);
    }

    private function azuriomVersion(): string
    {
        if (class_exists(\Azuriom\Azuriom::class) && method_exists(\Azuriom\Azuriom::class, 'version')) {
            return (string) \Azuriom\Azuriom::version();
        }

        return (string) (defined('AZURIOM_VERSION') ? AZURIOM_VERSION : config('app.version', '1.2.0'));
    }

    private function workDirectory(ExtensionOperation $operation): string
    {
        return storage_path('app/gaming-hub-manager/staging/'.$operation->operation_uuid);
    }

    private function incomingSwapPath(ExtensionOperation $operation): string
    {
        return $this->paths->pluginsRoot(true).'/.gaming-hub-manager-incoming-'.$operation->operation_uuid;
    }

    private function previousSwapPath(ExtensionOperation $operation): string
    {
        return $this->paths->pluginsRoot(true).'/.gaming-hub-manager-previous-'.$operation->operation_uuid;
    }

    private function backupPath(ExtensionOperation $operation, string $extensionId): string
    {
        $directory = storage_path('app/gaming-hub-manager/backups/'.now()->format('Ymd_His').'-'.$operation->operation_uuid);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ExtensionOperationFailed('Unable to create the update backup directory.');
        }

        return $directory.'/'.$extensionId;
    }

    private function cleanupNonFatal(string $path, ExtensionOperation $operation): void
    {
        try {
            $this->cleanupQuietly($path, false);
        } catch (\Throwable $exception) {
            $operation->appendEvent('cleaning', 'Cleanup warning: '.$this->messages->fromThrowable($exception), 'warning');
            $operation->save();
        }
    }

    private function cleanupQuietly(?string $path, bool $suppress = true): void
    {
        if ($path === null || ! file_exists($path)) {
            return;
        }

        try {
            $this->paths->deleteDirectory($path);
        } catch (\Throwable $exception) {
            if (! $suppress) {
                throw $exception;
            }
        }
    }
}
