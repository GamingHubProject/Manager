<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Illuminate\Support\Facades\Cache;

final class ExtensionUninstaller
{
    public function __construct(
        private ExtensionPathGuard $paths,
        private ExtensionDependencyGuard $dependencies,
        private AzuriomPluginLifecycle $lifecycle,
        private ExtensionSafeMessage $messages,
        private BackupManager $backups,
        private InstalledExtensionResolver $installed,
    ) {
    }

    public function uninstall(
        InstalledExtension $extension,
        ExtensionOperation $operation,
    ): void {
        $extensionId = $this->paths->validateId($extension->extension_id);
        $lock = Cache::lock('gaminghub-manager:package-operation:'.$extensionId, 180);
        $locked = false;
        $quarantine = null;
        $wasEnabled = false;
        $disabled = false;
        $filesMoved = false;
        $metadataDeleted = false;
        $metadata = $extension->getAttributes();

        try {
            if (! $lock->get()) {
                throw new ExtensionOperationFailed('Another lifecycle operation for this extension is already running.');
            }
            $locked = true;

            $operation->transition('resolving', 'Installed extension and dependency state resolved.');
            $this->dependencies->assertUninstallAllowed($extensionId);
            $live = $this->paths->destination($extensionId);
            $exists = is_dir($live);
            $wasEnabled = $exists ? $this->lifecycle->isEnabled($extensionId) : (bool) $extension->enabled_snapshot;
            $operation->mergeContext([
                'enabled_before' => $wasEnabled,
                'data_retained' => true,
                'directory_was_missing' => ! $exists,
            ]);

            if ($exists) {
                $operation->transition('backing_up', 'Creating a verified recovery backup before uninstall.');
                $backup = $this->backups->createFromPath(
                    $extensionId,
                    $extension->installed_version,
                    $live,
                    $wasEnabled,
                    $extension->manifest_snapshot,
                    $operation->actor_id,
                    'uninstall',
                    $operation->operation_uuid,
                );
                $operation->mergeContext(['backup_uuid' => $backup->backup_uuid]);
            }

            $operation->transition('disabling', 'Disabling the package before file removal.');
            if ($exists && $wasEnabled) {
                $disableResult = $this->lifecycle->disable($extensionId);
                $disabled = ! $this->lifecycle->isEnabled($extensionId);
                if (! $disableResult || ! $disabled) {
                    throw new ExtensionOperationFailed('Azuriom could not disable the extension.');
                }
            } elseif (! $exists) {
                $operation->appendEvent('disabling', 'Plugin directory was already missing; no executable files remained to disable.');
                $operation->save();
            }

            $operation->transition('removing', 'Removing extension files from the validated plugin directory.');
            if ($exists) {
                $quarantine = $this->paths->pluginsRoot(true)
                    .'/.gaming-hub-manager-uninstalling-'.$operation->operation_uuid;
                if (file_exists($quarantine) || ! rename($live, $quarantine)) {
                    throw new ExtensionOperationFailed('Unable to move extension files into guarded uninstall staging.');
                }
                $filesMoved = true;
            }

            $operation->transition('cleaning', 'Clearing plugin caches and removing installed-extension metadata.');
            $this->lifecycle->refresh();
            $extension->delete();
            $metadataDeleted = true;
            $this->lifecycle->refresh();

            if ($quarantine !== null && is_dir($quarantine)) {
                try {
                    $this->paths->deleteDirectory($quarantine);
                    $filesMoved = false;
                } catch (\Throwable $cleanupException) {
                    $operation->appendEvent('cleaning', 'Uninstall staging cleanup warning: '.$this->messages->fromThrowable($cleanupException), 'warning');
                    $operation->save();
                }
            }

            try {
                $this->paths->deletePublicAssets($extensionId);
            } catch (\Throwable $cleanupException) {
                $operation->appendEvent('cleaning', 'Public asset cleanup warning: '.$this->messages->fromThrowable($cleanupException), 'warning');
                $operation->save();
            }

            $this->installed->reconcileFilesystem();
            $operation->complete('Package files removed. Package database data was retained and a recovery backup was preserved.');
        } catch (\Throwable $exception) {
            $operation->mergeContext(['failed_stage' => $operation->current_stage ?: 'unknown']);
            $rollbackSucceeded = true;

            if ($filesMoved && $quarantine !== null && is_dir($quarantine)) {
                try {
                    $live = $this->paths->destination($extensionId);
                    if (file_exists($live) || ! rename($quarantine, $live)) {
                        $rollbackSucceeded = false;
                    }
                } catch (\Throwable) {
                    $rollbackSucceeded = false;
                }
            }

            if ($metadataDeleted || ! $extension->exists) {
                try {
                    $restored = new InstalledExtension();
                    $restored->forceFill($metadata);
                    $restored->exists = false;
                    $restored->save();
                } catch (\Throwable) {
                    $rollbackSucceeded = false;
                }
            }

            if ($disabled && $wasEnabled) {
                try {
                    $this->lifecycle->refresh();
                    if (! $this->lifecycle->enable($extensionId) || ! $this->lifecycle->isEnabled($extensionId)) {
                        $rollbackSucceeded = false;
                    }
                } catch (\Throwable) {
                    $rollbackSucceeded = false;
                }
            }

            try {
                $this->lifecycle->refresh();
                $this->installed->reconcileFilesystem();
            } catch (\Throwable) {
                $rollbackSucceeded = false;
            }

            $rollbackAttempted = $filesMoved || $disabled || $metadataDeleted;
            $operation->forceFill([
                'rollback_attempted' => $rollbackAttempted,
                'rollback_succeeded' => $rollbackAttempted ? $rollbackSucceeded : null,
            ])->save();
            $operation->fail(
                $this->messages->fromThrowable($exception),
                'uninstall_failed',
                $rollbackAttempted ? ($rollbackSucceeded ? 'rolled_back' : 'rollback_failed') : 'failed',
            );

            throw $exception;
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }
}
