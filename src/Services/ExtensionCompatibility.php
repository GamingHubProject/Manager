<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionCompatibilityFailed;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;

final class ExtensionCompatibility
{
    public function __construct(private ExtensionVersionPolicy $versions)
    {
    }

    public function assertCompatible(ExtensionManifest $manifest, ?string $coreVersion, string $azuriomVersion, string $phpVersion): void
    {
        $checks = [
            'azuriom' => $azuriomVersion,
            'php' => $phpVersion,
        ];
        if (isset($manifest->requires['gaming-hub-core'])) {
            $constraint = $manifest->requires['gaming-hub-core'];
            $satisfied = is_string($constraint)
                && $coreVersion !== null
                && $this->versions->satisfiesPackageDependency('gaming-hub-core', $coreVersion, $constraint);
            if (! $satisfied) {
                throw new ExtensionCompatibilityFailed($this->dependencyFailure(
                    $manifest->id,
                    'gaming-hub-core',
                    $coreVersion,
                    is_string($constraint) ? $constraint : '(invalid)',
                    $satisfied,
                ));
            }
        }

        foreach ($checks as $key => $version) {
            $constraint = $manifest->requires[$key] ?? null;
            if (! is_string($constraint) || ! $this->versions->satisfies($version, $constraint)) {
                throw new ExtensionCompatibilityFailed($key.' '.$constraint.' is required.');
            }
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

}
