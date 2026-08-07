<?php

namespace Azuriom\Plugin\GamingHubManager\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Azuriom\Plugin\GamingHubManager\Models\PackageBackup;
use Azuriom\Plugin\GamingHubManager\Services\BackupManager;
use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
use Azuriom\Plugin\GamingHubManager\Services\ManagerSettings;
use Azuriom\Plugin\GamingHubManager\Services\PackageCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use ZipArchive;

final class DashboardController extends Controller
{
    public function __construct(
        private ManagerRuntime $runtime,
        private PackageCatalog $catalog,
        private ManagerSettings $settings,
        private BackupManager $backups,
    ) {
    }

    public function overview(): View
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return $this->migrationRequired($runtimeStatus);
        }
        $snapshot = $this->snapshotWithLegacyAlerts($runtimeStatus);

        return view('gaming-hub-manager::admin.overview', [
            ...$snapshot,
            'legacy' => $runtimeStatus,
            'recentOperations' => ExtensionOperation::query()->latest('started_at')->limit(10)->get(),
            'backupCount' => PackageBackup::query()->count(),
            'changedCount' => InstalledExtension::query()->where('integrity_status', 'changed')->count(),
        ]);
    }

    public function installed(): View
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return $this->migrationRequired($runtimeStatus);
        }

        return view('gaming-hub-manager::admin.installed', $this->snapshotWithLegacyAlerts($runtimeStatus));
    }

    public function available(): View
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return $this->migrationRequired($runtimeStatus);
        }

        return view('gaming-hub-manager::admin.available', $this->snapshotWithLegacyAlerts($runtimeStatus));
    }

    public function registries(): View
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return $this->migrationRequired($runtimeStatus);
        }

        return view('gaming-hub-manager::admin.registries', $this->snapshotWithLegacyAlerts($runtimeStatus));
    }

    public function logs(): View
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return $this->migrationRequired($runtimeStatus);
        }

        return view('gaming-hub-manager::admin.logs', [
            'operations' => ExtensionOperation::query()->latest('started_at')->paginate(50),
        ]);
    }

    public function backups(): View
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return $this->migrationRequired($runtimeStatus);
        }

        return view('gaming-hub-manager::admin.backups', [
            'backups' => PackageBackup::query()->latest()->paginate(50),
            'backupPath' => $this->backups->root(),
        ]);
    }

    public function settings(): View
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return $this->migrationRequired($runtimeStatus);
        }
        $pluginRoot = base_path('plugins');
        $storageRoot = storage_path('app/gaming-hub-manager');

        return view('gaming-hub-manager::admin.settings', [
            'settings' => $this->settings->all(),
            'diagnostics' => [
                'php' => PHP_VERSION,
                'zip' => class_exists(ZipArchive::class),
                'plugin_root' => $pluginRoot,
                'plugin_root_writable' => is_dir($pluginRoot) && is_writable($pluginRoot),
                'storage_root' => $storageRoot,
                'storage_root_writable' => (is_dir($storageRoot) && is_writable($storageRoot)) || is_writable(dirname($storageRoot)),
            ],
        ]);
    }

    /**
     * @param array{warnings?: list<string>} $legacy
     * @return array<string, mixed>
     */
    private function snapshotWithLegacyAlerts(array $legacy): array
    {
        $snapshot = $this->catalog->snapshot(false);
        foreach ($legacy['warnings'] ?? [] as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $snapshot['managerAlerts'][] = [
                    'level' => 'warning',
                    'label' => 'Legacy import',
                    'message' => $warning,
                ];
            }
        }

        return $snapshot;
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return redirect()->route('gaming-hub-manager.admin.overview')
                ->with('warning', $this->runtime->recoveryMessage($runtimeStatus));
        }

        $data = $request->validate([
            'allow_private_hosts' => ['sometimes', 'boolean'],
            'retain_successful_update_backups' => ['sometimes', 'boolean'],
            'auto_import_legacy_core_metadata' => ['sometimes', 'boolean'],
            'stale_staging_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'operation_log_retention_days' => ['required', 'integer', 'min:7', 'max:3650'],
        ]);
        foreach (['allow_private_hosts', 'retain_successful_update_backups', 'auto_import_legacy_core_metadata'] as $key) {
            $data[$key] = $request->boolean($key);
        }
        $this->settings->update($data);

        return back()->with('success', 'Gaming Hub Manager settings saved.');
    }

    private function migrationRequired(array $runtimeStatus): View
    {
        return view('gaming-hub-manager::admin.migration-required', compact('runtimeStatus'));
    }
}
