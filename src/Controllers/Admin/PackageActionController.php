<?php

namespace Azuriom\Plugin\GamingHubManager\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Azuriom\Plugin\GamingHubManager\Services\AzuriomPluginLifecycle;
use Azuriom\Plugin\GamingHubManager\Services\BackupManager;
use Azuriom\Plugin\GamingHubManager\Services\DirectoryHasher;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionDependencyGuard;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionInstaller;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionPathGuard;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSafeMessage;
use Azuriom\Plugin\GamingHubManager\Services\InstalledExtensionResolver;
use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
use Azuriom\Plugin\GamingHubManager\Services\PackageCatalog;
use Azuriom\Plugin\GamingHubManager\Services\PackageReleaseResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PackageActionController extends Controller
{
    public function __construct(
        private ManagerRuntime $runtime,
        private ExtensionInstaller $installer,
        private PackageReleaseResolver $releases,
        private PackageCatalog $catalog,
        private InstalledExtensionResolver $installed,
        private AzuriomPluginLifecycle $lifecycle,
        private ExtensionDependencyGuard $dependencies,
        private ExtensionSafeMessage $messages,
        private DirectoryHasher $hasher,
        private ExtensionPathGuard $paths,
        private BackupManager $backups,
    ) {
    }

    public function install(Request $request, string $source): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $source = ExtensionSource::query()->findOrFail($source);
        $data = $request->validate([
            'extension_id' => ['required', 'string', 'max:100'],
            'enable' => ['sometimes', 'boolean'],
            'confirm_unverified' => ['sometimes', 'accepted'],
        ]);
        if ($error = $this->sourceError($request, $source, 'installation')) {
            return $error;
        }

        $operation = $this->newOperation('install', $source, $request, $data['extension_id']);
        try {
            $resolved = $this->releases->resolve($source, $data['extension_id'], $operation);
            $package = $this->installer->install(
                $source,
                $resolved['release'],
                $resolved['asset'],
                $resolved['checksum'],
                (int) $request->user()->getKey(),
                $request->boolean('enable'),
                $data['extension_id'],
                $resolved['metadata'],
                $operation,
                $resolved['checksumSource'],
            );

            return redirect()->route('gaming-hub-manager.admin.packages.show', $package)
                ->with('success', 'Package installed successfully.');
        } catch (\Throwable $exception) {
            return $this->failure($operation, 'Install', $exception);
        }
    }

    public function update(Request $request, string $extension): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }

        return $this->replace($request, InstalledExtension::query()->findOrFail($extension), false);
    }

    public function reinstall(Request $request, string $extension): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }

        return $this->replace($request, InstalledExtension::query()->findOrFail($extension), true);
    }

    public function enable(Request $request, string $extension): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }

        return $this->changeLifecycle($request, InstalledExtension::query()->findOrFail($extension), true);
    }

    public function disable(Request $request, string $extension): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $extension = InstalledExtension::query()->findOrFail($extension);
        if ($protected = $this->protectedPackage($extension, 'disable')) {
            return $protected;
        }

        $dependents = $this->dependencies->dependentsOf($extension->extension_id);
        if ($dependents !== [] && ! $request->boolean('confirm_dependents')) {
            return back()->with('error', 'Enabled dependents may be affected: '.implode(', ', array_column($dependents, 'id')).'.');
        }

        return $this->changeLifecycle($request, $extension, false);
    }

    public function verify(Request $request, string $extension): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $extension = InstalledExtension::query()->findOrFail($extension);
        $operation = $this->newOperation('verify', null, $request, $extension->extension_id);
        $lock = Cache::lock('gaminghub-manager:package-operation:'.$extension->extension_id, 120);
        $locked = false;
        try {
            if (! $lock->get()) {
                throw new \RuntimeException('Another lifecycle operation for this package is already running.');
            }
            $locked = true;
            $operation->transition('validating', 'Calculating the installed package integrity hash.');
            $path = $this->paths->destination($extension->extension_id, true);
            $actual = $this->hasher->hash($path);
            $expected = $extension->integrity_hash;
            $status = $expected === null || hash_equals($expected, $actual) ? 'verified' : 'changed';
            $extension->forceFill([
                'integrity_hash' => $expected ?? $actual,
                'integrity_status' => $status,
                'integrity_checked_at' => now(),
                'enabled_snapshot' => $this->lifecycle->isEnabled($extension->extension_id),
                'last_operation_result' => $status,
            ])->save();
            $operation->mergeContext(['expected_hash' => $expected, 'actual_hash' => $actual]);
            $operation->complete($status === 'verified'
                ? 'Package integrity verified.'
                : 'Package files differ from the recorded integrity baseline.');

            return back()->with($status === 'verified' ? 'success' : 'warning',
                $status === 'verified' ? 'Package integrity verified.' : 'Package files have changed since installation.');
        } catch (\Throwable $exception) {
            return $this->failure($operation, 'Integrity verification', $exception);
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    public function backup(Request $request, string $extension): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $extension = InstalledExtension::query()->findOrFail($extension);
        if ($protected = $this->protectedPackage($extension, 'back up')) {
            return $protected;
        }

        $operation = $this->newOperation('backup', null, $request, $extension->extension_id);
        try {
            $backup = $this->backups->create($extension, (int) $request->user()->getKey(), $operation);

            return redirect()->route('gaming-hub-manager.admin.backups')
                ->with('success', 'Backup '.$backup->backup_uuid.' created.');
        } catch (\Throwable $exception) {
            return $this->failure($operation, 'Backup', $exception);
        }
    }

    private function replace(Request $request, InstalledExtension $extension, bool $reinstall): RedirectResponse
    {
        if ($protected = $this->protectedPackage($extension, $reinstall ? 'reinstall' : 'update')) {
            return $protected;
        }

        $request->validate([
            'source_id' => ['nullable', 'integer', 'exists:gaminghub_manager_sources,id'],
            'confirm_unverified' => ['sometimes', 'accepted'],
        ]);
        $catalogItem = $request->filled('source_id')
            ? null
            : $this->catalog->findForPackage($extension->extension_id);
        $source = $request->filled('source_id')
            ? ExtensionSource::query()->findOrFail((int) $request->input('source_id'))
            : ($catalogItem['source'] ?? null);
        if ($source === null) {
            return back()->with('error', 'No enabled source currently provides this package.');
        }
        if ($error = $this->sourceError($request, $source, $reinstall ? 'reinstall' : 'update')) {
            return $error;
        }

        $operation = $this->newOperation($reinstall ? 'reinstall' : 'update', $source, $request, $extension->extension_id);
        try {
            $this->installed->reconcileFilesystem();
            $extension = $this->installed->resolve($extension->extension_id);
            $resolved = $this->releases->resolve($source, $extension->extension_id, $operation);
            $package = $this->installer->update(
                $extension,
                $source,
                $resolved['release'],
                $resolved['asset'],
                $resolved['checksum'],
                (int) $request->user()->getKey(),
                $resolved['metadata'],
                $reinstall,
                $operation,
                $resolved['checksumSource'],
            );

            return redirect()->route('gaming-hub-manager.admin.packages.show', $package)
                ->with('success', $reinstall ? 'Package reinstalled successfully.' : 'Package updated successfully.');
        } catch (\Throwable $exception) {
            return $this->failure($operation, $reinstall ? 'Reinstall' : 'Update', $exception);
        }
    }

    private function changeLifecycle(Request $request, InstalledExtension $extension, bool $enable): RedirectResponse
    {
        if ($protected = $this->protectedPackage($extension, $enable ? 'enable' : 'disable')) {
            return $protected;
        }

        $operation = $this->newOperation($enable ? 'enable' : 'disable', null, $request, $extension->extension_id);
        $lock = Cache::lock('gaminghub-manager:package-operation:'.$extension->extension_id, 120);
        $locked = false;
        try {
            if (! $lock->get()) {
                throw new \RuntimeException('Another lifecycle operation for this package is already running.');
            }
            $locked = true;
            $operation->transition($enable ? 'enabling' : 'disabling', $enable ? 'Enabling package.' : 'Disabling package.');
            $successful = $enable
                ? $this->lifecycle->enable($extension->extension_id)
                : $this->lifecycle->disable($extension->extension_id);
            if (! $successful || $this->lifecycle->isEnabled($extension->extension_id) !== $enable) {
                throw new \RuntimeException('Azuriom did not apply the requested plugin lifecycle state.');
            }
            $extension->update(['enabled_snapshot' => $enable, 'last_operation_result' => 'completed']);
            $operation->complete($enable ? 'Package enabled.' : 'Package disabled.');

            return back()->with('success', $enable ? 'Package enabled.' : 'Package disabled.');
        } catch (\Throwable $exception) {
            return $this->failure($operation, 'Lifecycle action', $exception);
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    private function notReady(): ?RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if ($this->runtime->isReady($runtimeStatus)) {
            return null;
        }

        return redirect()->route('gaming-hub-manager.admin.overview')
            ->with('warning', 'Run the pending Gaming Hub Manager migrations before managing packages.');
    }

    private function sourceError(Request $request, ExtensionSource $source, string $action): ?RedirectResponse
    {
        if (! $source->enabled) {
            return back()->with('error', 'The selected package source is disabled for '.$action.'.');
        }
        if (! $source->trusted && $source->type !== 'official' && ! $request->boolean('confirm_unverified')) {
            return back()->with('error', 'Explicit untrusted package confirmation is required for '.$action.'.');
        }

        return null;
    }


    private function protectedPackage(InstalledExtension $extension, string $action): ?RedirectResponse
    {
        if ($extension->extension_id !== 'gaming-hub-manager') {
            return null;
        }

        return back()->with(
            'error',
            'Gaming Hub Manager reports its own installation but cannot '.$action.' itself.',
        );
    }

    private function newOperation(string $type, ?ExtensionSource $source, Request $request, ?string $packageId): ExtensionOperation
    {
        return ExtensionOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'operation' => $type,
            'extension_id' => $packageId === 'direct' ? null : $packageId,
            'source_id' => $source?->source_id,
            'actor_id' => $request->user()?->getKey(),
            'started_at' => now(),
            'result' => 'running',
            'current_stage' => 'queued',
            'events' => [[
                'at' => now()->toIso8601String(),
                'stage' => 'queued',
                'level' => 'info',
                'message' => ucfirst($type).' operation queued.',
            ]],
        ]);
    }

    private function failure(ExtensionOperation $operation, string $label, \Throwable $exception): RedirectResponse
    {
        if ($operation->result === 'running' && $operation->finished_at === null) {
            $operation->fail($this->messages->fromThrowable($exception), strtolower(str_replace(' ', '_', $label)).'_failed');
        }
        $operation->refresh();
        $stage = $operation->context['failed_stage'] ?? $operation->current_stage ?? 'unknown';
        $rollback = $operation->rollback_attempted
            ? ($operation->rollback_succeeded ? ' Rollback succeeded.' : ' Rollback failed; inspect the operation log.')
            : '';

        return redirect()->route('gaming-hub-manager.admin.logs')
            ->with('error', $label.' failed during '.$stage.'. '.$this->messages->fromThrowable($exception).$rollback);
    }
}
