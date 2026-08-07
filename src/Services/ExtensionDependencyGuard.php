<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;

final class ExtensionDependencyGuard
{
    public function __construct(
        private ExtensionVersionPolicy $versions,
        private ExtensionPathGuard $paths,
    ) {
    }

    public function assertCandidateDependencies(ExtensionManifest $candidate): void
    {
        foreach (($candidate->requires['extensions'] ?? []) as $dependencyId => $constraint) {
            if (! is_string($dependencyId) || ! is_string($constraint)) {
                throw new ExtensionOperationFailed('The extension declares an invalid dependency.');
            }

            $installed = InstalledExtension::where('extension_id', $dependencyId)->first();
            $satisfied = $installed !== null
                && $this->versions->satisfiesPackageDependency($dependencyId, $installed->installed_version, $constraint);
            if (! $satisfied) {
                throw new ExtensionOperationFailed($this->dependencyFailure(
                    $candidate->id,
                    $dependencyId,
                    $installed?->installed_version,
                    $constraint,
                    $satisfied,
                ));
            }
        }
    }

    public function assertUpdateAllowed(ExtensionManifest $candidate): void
    {
        $this->assertCandidateDependencies($candidate);

        foreach ($this->dependentsOf($candidate->id) as $dependent) {
            $satisfied = $this->versions->satisfiesPackageDependency($candidate->id, $candidate->version, $dependent['constraint']);
            if (! $satisfied) {
                throw new ExtensionOperationFailed($this->dependencyFailure(
                    $dependent['id'],
                    $candidate->id,
                    $candidate->version,
                    $dependent['constraint'],
                    $satisfied,
                ));
            }
        }
    }

    public function assertUninstallAllowed(string $extensionId): void
    {
        if ($extensionId === 'gaming-hub-manager') {
            throw new ExtensionOperationFailed('Gaming Hub Manager cannot uninstall itself.');
        }

        $dependents = $this->dependentsOf($extensionId);
        if ($dependents !== []) {
            throw new ExtensionOperationFailed(
                'Uninstall blocked because these installed extensions depend on it: '.implode(', ', array_column($dependents, 'id')).'.',
            );
        }
    }

    private function dependencyFailure(
        string $candidateId,
        string $dependencyId,
        ?string $installedVersion,
        string $constraint,
        bool $satisfied,
    ): string {
        $installed = InstalledExtension::query()
            ->orderBy('extension_id')
            ->pluck('installed_version', 'extension_id')
            ->map(static fn ($version): string => (string) $version)
            ->all();

        return 'Dependency validation failed: package='.$candidateId
            .'; requested='.$dependencyId
            .'; installed_packages='.json_encode($installed, JSON_UNESCAPED_SLASHES)
            .'; installed_version='.($installedVersion ?? 'missing')
            .'; constraint='.$constraint
            .'; comparison='.($satisfied ? 'satisfied' : 'not_satisfied').'.';
    }

    /**
     * @return array<int, array{id: string, constraint: string}>
     */
    public function dependentsOf(string $extensionId): array
    {
        $dependents = [];

        foreach (InstalledExtension::where('extension_id', '!=', $extensionId)->get() as $candidate) {
            $constraint = $candidate->manifest_snapshot['requires']['extensions'][$extensionId] ?? null;
            if (is_string($constraint) && $constraint !== '') {
                $dependents[$candidate->extension_id] = [
                    'id' => $candidate->extension_id,
                    'constraint' => $constraint,
                ];
                continue;
            }

            try {
                $pluginPath = $this->paths->destination($candidate->extension_id).'/plugin.json';
                if (! is_file($pluginPath)) {
                    continue;
                }

                $plugin = json_decode((string) file_get_contents($pluginPath), true);
                foreach (($plugin['dependencies'] ?? []) as $dependency => $minimum) {
                    $dependency = (string) $dependency;
                    $optional = str_ends_with($dependency, '?');
                    $dependency = rtrim($dependency, '?');
                    if (! $optional && $dependency === $extensionId && is_string($minimum)) {
                        $dependents[$candidate->extension_id] = [
                            'id' => $candidate->extension_id,
                            'constraint' => $minimum,
                        ];
                    }
                }
            } catch (\Throwable) {
                // A malformed unrelated plugin cannot widen the deletion target.
            }
        }

        return array_values($dependents);
    }
}
