<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;

final class ManagerRuntime
{
    private bool $prepared = false;
    private array $legacySummary = [];

    public function __construct(
        private ManagerSettings $settings,
        private ExtensionSourceManager $sources,
        private LegacyMetadataImporter $legacy,
        private InstalledExtensionResolver $installed,
        private ExtensionPathGuard $paths,
    ) {
    }

    public function prepare(): array
    {
        if ($this->prepared) {
            return $this->legacySummary;
        }
        $this->prepared = true;

        $this->settings->applyToConfig();
        $this->closeInterruptedOperations();
        $this->sources->ensureOfficial();
        $this->legacySummary = $this->legacy->import();
        $this->installed->reconcileFilesystem();
        $this->cleanupStaging();
        $this->pruneLogs();

        return $this->legacySummary;
    }

    private function closeInterruptedOperations(): void
    {
        ExtensionOperation::query()
            ->where('result', 'running')
            ->whereNull('finished_at')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->get()
            ->each(fn (ExtensionOperation $operation) => $operation->fail(
                'Operation stopped updating and was marked as interrupted.',
                'interrupted',
            ));
    }

    private function cleanupStaging(): void
    {
        $root = storage_path('app/gaming-hub-manager/staging');
        if (! is_dir($root)) {
            return;
        }
        $cutoff = time() - ((int) config('gaming-hub-manager.manager.stale_staging_hours', 24) * 3600);
        foreach (glob($root.'/*') ?: [] as $path) {
            if ((filemtime($path) ?: time()) >= $cutoff) {
                continue;
            }
            try {
                is_dir($path) ? $this->paths->deleteDirectory($path) : @unlink($path);
            } catch (\Throwable) {
            }
        }
    }

    private function pruneLogs(): void
    {
        $days = (int) config('gaming-hub-manager.manager.operation_log_retention_days', 180);
        ExtensionOperation::query()
            ->whereIn('result', ['completed', 'failed'])
            ->where('finished_at', '<', now()->subDays($days))
            ->delete();
    }
}
