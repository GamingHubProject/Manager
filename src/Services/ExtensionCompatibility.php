<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionCompatibilityFailed;

final class ExtensionCompatibility
{
    public function __construct(private ExtensionVersionPolicy $versions)
    {
    }

    public function assertCompatible(ExtensionManifest $manifest, string $azuriomVersion, string $phpVersion): void
    {
        foreach ([
            'azuriom' => $azuriomVersion,
            'php' => $phpVersion,
        ] as $key => $version) {
            $constraint = $manifest->requires[$key] ?? null;
            if (! is_string($constraint) || ! $this->versions->satisfies($version, $constraint)) {
                throw new ExtensionCompatibilityFailed($key.' '.$constraint.' is required.');
            }
        }
    }
}
