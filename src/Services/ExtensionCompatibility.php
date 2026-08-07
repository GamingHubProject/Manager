<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionCompatibilityFailed;

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
            if ($coreVersion === null) {
                throw new ExtensionCompatibilityFailed('Gaming Hub Core is required but is not installed.');
            }
            $checks['gaming-hub-core'] = $coreVersion;
        }

        foreach ($checks as $key => $version) {
            $constraint = $manifest->requires[$key] ?? null;
            if (! is_string($constraint) || ! $this->versions->satisfies($version, $constraint)) {
                throw new ExtensionCompatibilityFailed($key.' '.$constraint.' is required.');
            }
        }
    }
}
