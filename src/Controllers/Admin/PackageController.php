<?php

namespace Azuriom\Plugin\GamingHubManager\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Azuriom\Plugin\GamingHubManager\Models\PackageBackup;
use Azuriom\Plugin\GamingHubManager\Services\AzuriomPluginLifecycle;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionDependencyGuard;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSafeMessage;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionUninstaller;
use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
use Azuriom\Plugin\GamingHubManager\Services\PackageCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PackageController extends Controller
{
    public function __construct(
        private ManagerRuntime $runtime,
        private PackageCatalog $catalog,
        private AzuriomPluginLifecycle $lifecycle,
        private ExtensionDependencyGuard $dependencies,
        private ExtensionUninstaller $uninstaller,
        private ExtensionSafeMessage $messages,
    ) {
    }

    public function show(string $extension): View|RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return view('gaming-hub-manager::admin.migration-required', compact('runtimeStatus'));
        }
        $extensionModel = InstalledExtension::query()->findOrFail($extension);
        $protectedPackage = $extensionModel->extension_id === 'gaming-hub-manager';

        return view('gaming-hub-manager::admin.package', [
            'extension' => $extensionModel,
            'enabled' => $this->lifecycle->isEnabled($extensionModel->extension_id),
            'dependents' => $this->dependencies->dependentsOf($extensionModel->extension_id),
            'catalogItem' => $protectedPackage ? null : $this->catalog->findForPackage($extensionModel->extension_id),
            'protectedPackage' => $protectedPackage,
            'backups' => PackageBackup::query()->where('extension_id', $extensionModel->extension_id)->latest()->limit(10)->get(),
            'operations' => ExtensionOperation::query()->where('extension_id', $extensionModel->extension_id)->latest('started_at')->limit(20)->get(),
        ]);
    }

    public function confirmUninstall(string $extension): View|RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return view('gaming-hub-manager::admin.migration-required', compact('runtimeStatus'));
        }
        $extensionModel = InstalledExtension::query()->findOrFail($extension);
        if ($extensionModel->extension_id === 'gaming-hub-manager') {
            return redirect()->route('gaming-hub-manager.admin.packages.show', $extensionModel)
                ->with('error', 'Gaming Hub Manager reports its own installation but cannot uninstall itself.');
        }

        return view('gaming-hub-manager::admin.uninstall', [
            'extension' => $extensionModel,
            'enabled' => $this->lifecycle->isEnabled($extensionModel->extension_id),
            'dependents' => $this->dependencies->dependentsOf($extensionModel->extension_id),
        ]);
    }

    public function destroy(Request $request, string $extension): RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return redirect()->route('gaming-hub-manager.admin.overview')
                ->with('warning', 'Run the pending Gaming Hub Manager migrations before uninstalling packages.');
        }
        $extensionModel = InstalledExtension::query()->findOrFail($extension);
        if ($extensionModel->extension_id === 'gaming-hub-manager') {
            return redirect()->route('gaming-hub-manager.admin.packages.show', $extensionModel)
                ->with('error', 'Gaming Hub Manager reports its own installation but cannot uninstall itself.');
        }
        $request->validate([
            'confirmation' => ['required', 'string', 'in:'.$extensionModel->extension_id],
            'retain_data' => ['required', 'accepted'],
        ], [
            'confirmation.in' => 'Type the exact package ID to confirm uninstall.',
            'retain_data.accepted' => 'Gaming Hub Manager only supports file uninstall with package database data retained.',
        ]);

        $operation = ExtensionOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'operation' => 'uninstall',
            'extension_id' => $extensionModel->extension_id,
            'version' => $extensionModel->installed_version,
            'source_id' => $extensionModel->source_id,
            'actor_id' => $request->user()->getKey(),
            'started_at' => now(),
            'result' => 'running',
            'current_stage' => 'queued',
            'events' => [[
                'at' => now()->toIso8601String(),
                'stage' => 'queued',
                'level' => 'info',
                'message' => 'Uninstall operation queued with database data retention enabled.',
            ]],
        ]);

        try {
            $this->uninstaller->uninstall($extensionModel, $operation);

            return redirect()->route('gaming-hub-manager.admin.backups')
                ->with('success', 'Package files removed. A verified recovery backup was retained.');
        } catch (\Throwable $exception) {
            if ($operation->result === 'running' && $operation->finished_at === null) {
                $operation->fail($this->messages->fromThrowable($exception), 'uninstall_failed');
            }

            return redirect()->route('gaming-hub-manager.admin.logs')
                ->with('error', 'Uninstall failed: '.$this->messages->fromThrowable($exception));
        }
    }
}
