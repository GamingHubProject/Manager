<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Extensions\Plugin\PluginManager;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Illuminate\Support\Facades\Artisan;

final class AzuriomPluginLifecycle
{
    public function isEnabled(string $extensionId): bool
    {
        $manager = $this->manager();

        return $manager !== null && $manager->isEnabled($extensionId);
    }

    public function enable(string $extensionId): bool
    {
        $manager = $this->manager();
        if ($manager !== null) {
            return $manager->enable($extensionId);
        }

        return $this->callLifecycleCommand('plugin:enable', $extensionId);
    }

    public function disable(string $extensionId): bool
    {
        $manager = $this->manager();
        if ($manager !== null) {
            return $manager->disable($extensionId);
        }

        return $this->callLifecycleCommand('plugin:disable', $extensionId);
    }

    public function assertRequirementsSatisfied(string $extensionId): void
    {
        $manager = $this->manager();
        if ($manager === null) {
            return;
        }

        $missing = $manager->getMissingRequirements($extensionId);
        if ($missing !== null) {
            throw new ExtensionOperationFailed(
                'Azuriom dependency validation failed for '.$extensionId.': missing or incompatible dependency '.$missing.'.',
            );
        }
    }

    public function migrate(string $extensionId): void
    {
        $path = base_path('plugins/'.$extensionId.'/database/migrations');
        if (! is_dir($path)) {
            return;
        }

        $migrator = app('migrator');
        $migrator->run([$path]);
    }

    public function refresh(): void
    {
        $manager = $this->manager();
        if ($manager !== null) {
            $manager->cachePlugins();
            $manager->purgeInternalCache();

            return;
        }

        foreach (['plugin:cache', 'view:clear', 'route:clear', 'config:clear'] as $command) {
            if (array_key_exists($command, Artisan::all())) {
                Artisan::call($command);
            }
        }
    }

    private function manager(): ?PluginManager
    {
        if (! class_exists(PluginManager::class)) {
            return null;
        }

        return app(PluginManager::class);
    }

    private function callLifecycleCommand(string $command, string $extensionId): bool
    {
        if (! array_key_exists($command, Artisan::all())) {
            throw new ExtensionOperationFailed('This Azuriom version does not expose the required plugin lifecycle action.');
        }

        return Artisan::call($command, ['id' => $extensionId]) === 0;
    }
}
