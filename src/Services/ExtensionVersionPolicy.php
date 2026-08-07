<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionAlreadyCurrent;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Composer\Semver\Semver;

final class ExtensionVersionPolicy
{
    public function normalize(string $version): string
    {
        return ltrim(trim($version), "vV");
    }

    public function compare(string $candidate, string $installed): int
    {
        return version_compare($this->normalize($candidate), $this->normalize($installed));
    }

    public function assertNewer(string $candidate, string $installed): void
    {
        $comparison = $this->compare($candidate, $installed);

        if ($comparison === 0) {
            throw new ExtensionAlreadyCurrent('The selected extension version is already installed.');
        }

        if ($comparison < 0) {
            throw new ExtensionOperationFailed('Extension downgrades are blocked.');
        }
    }

    public function satisfies(string $version, string $constraint): bool
    {
        return Semver::satisfies($this->normalize($version), trim($constraint));
    }
}
