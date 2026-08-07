<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;

final class ReleaseVersionValidator
{
    public function __construct(private ExtensionVersionPolicy $versions)
    {
    }

    public function releaseVersion(array $release): string
    {
        $tag = $release['tag_name'] ?? null;
        if (! is_string($tag)) {
            throw new ExtensionOperationFailed('GitHub Release tag is missing.');
        }

        $version = $this->versions->normalize($tag);
        if (! $this->isSemanticVersion($version)) {
            throw new ExtensionOperationFailed('GitHub Release tag is not a supported semantic version.');
        }

        return $version;
    }

    public function assetVersion(string $assetName): ?string
    {
        $name = basename($assetName);
        if (! str_ends_with(strtolower($name), '.zip')) {
            return null;
        }

        $stem = substr($name, 0, -4);
        if (! preg_match('/(?:^|[-_.])v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?(?:\+[0-9A-Za-z][0-9A-Za-z.-]*)?)$/', $stem, $matches)) {
            return null;
        }

        return $this->versions->normalize($matches[1]);
    }

    public function assertConsistent(array $release, array $asset, ExtensionManifest $manifest, ?array $metadata = null): void
    {
        $releaseVersion = $this->releaseVersion($release);
        if ($manifest->version !== $releaseVersion) {
            throw new ExtensionOperationFailed('GitHub Release tag and plugin.json version do not match.');
        }

        $assetName = $asset['name'] ?? null;
        if (! is_string($assetName) || trim($assetName) === '') {
            throw new ExtensionOperationFailed('Selected GitHub Release asset name is missing.');
        }

        $assetVersion = $this->assetVersion($assetName);
        if ($assetVersion !== null && $assetVersion !== $releaseVersion) {
            throw new ExtensionOperationFailed('Versioned release asset filename and GitHub tag do not match.');
        }

        $selectedVersion = $metadata['selected_version'] ?? null;
        if (is_string($selectedVersion) && $this->versions->normalize($selectedVersion) !== $releaseVersion) {
            throw new ExtensionOperationFailed('Resolved release version changed before package validation.');
        }
    }

    public function isSemanticVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?(?:\+[0-9A-Za-z][0-9A-Za-z.-]*)?$/', $version) === 1;
    }

    public function isPrerelease(string $version): bool
    {
        return str_contains($version, '-');
    }
}
