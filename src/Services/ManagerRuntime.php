<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;

final class ManagerRuntime
{
    private bool $prepared = false;
    private array $runtimeSummary = [];

    public function __construct(
        private ManagerSchema $schema,
        private ManagerSettings $settings,
        private ExtensionSourceManager $sources,
        private LegacyMetadataImporter $legacy,
        private InstalledExtensionResolver $installed,
        private ExtensionPathGuard $paths,
    ) {
    }

    /**
     * @return array{
     *     schema_ready: bool,
     *     database_available: bool,
     *     missing_tables: list<string>,
     *     sources: int,
     *     packages: int,
     *     operations: int,
     *     backups: int,
     *     warnings: list<string>,
     *     disabled?: bool,
     *     detected?: bool,
     *     throttled?: bool,
     *     last_run?: string
     * }
     */
    public function prepare(): array
    {
        if ($this->prepared) {
            return $this->runtimeSummary;
        }
        $this->prepared = true;

        $status = $this->schema->status(true);
        $empty = [
            'sources' => 0,
            'packages' => 0,
            'operations' => 0,
            'backups' => 0,
            'warnings' => [],
        ];
        if (! $status['schema_ready']) {
            return $this->runtimeSummary = [...$status, ...$empty];
        }

        $this->settings->applyToConfig();
        $this->closeInterruptedOperations();
        $this->sources->ensureOfficial();
        $legacy = $this->legacy->import();
        $this->installed->reconcileFilesystem();
        $this->cleanupStaging();
        $this->pruneLogs();

        return $this->runtimeSummary = [...$status, ...$legacy];
    }

    public function isReady(?array $summary = null): bool
    {
        $summary ??= $this->prepare();

        return (bool) ($summary['schema_ready'] ?? false);
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
