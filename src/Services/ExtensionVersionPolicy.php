<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionAlreadyCurrent;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;

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
        if (class_exists(\Composer\Semver\Semver::class)) {
            return \Composer\Semver\Semver::satisfies($this->normalize($version), trim($constraint));
        }

        $constraint = trim($constraint);
        $version = $this->normalize($version);

        if (preg_match('/^(>=|<=|>|<|=)?\s*(\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?)$/', $constraint, $matches)) {
            $operator = $matches[1] !== '' ? $matches[1] : '=';
            $target = substr_count($matches[2], '.') === 1 ? $matches[2].'.0' : $matches[2];

            return version_compare($version, $target, $operator === '=' ? '==' : $operator);
        }

        if (preg_match('/^\^(\d+)\.(\d+)\.(\d+)$/', $constraint, $matches)) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
            $patch = (int) $matches[3];
            $minimum = "{$major}.{$minor}.{$patch}";
            $maximum = $major > 0
                ? ($major + 1).'.0.0'
                : ($minor > 0 ? "0.".($minor + 1).'.0' : "0.0.".($patch + 1));

            return version_compare($version, $minimum, '>=') && version_compare($version, $maximum, '<');
        }

        if (preg_match('/^~(\d+)\.(\d+)\.(\d+)$/', $constraint, $matches)) {
            $minimum = "{$matches[1]}.{$matches[2]}.{$matches[3]}";
            $maximum = $matches[1].'.'.((int) $matches[2] + 1).'.0';

            return version_compare($version, $minimum, '>=') && version_compare($version, $maximum, '<');
        }

        return false;
    }
}
