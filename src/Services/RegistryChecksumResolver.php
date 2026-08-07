<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;

final class RegistryChecksumResolver
{
    public function __construct(private ExtensionVersionPolicy $versions)
    {
    }

    public function resolve(array $metadata, string $selectedVersion, string $assetName): ?string
    {
        $selectedVersion = $this->versions->normalize($selectedVersion);
        $assetName = basename($assetName);

        $structured = $this->structured($metadata['checksums'] ?? null, $selectedVersion, $assetName);
        if ($structured !== null) {
            return $structured;
        }

        $checksum = $metadata['sha256'] ?? null;
        if ($checksum === null) {
            return null;
        }

        $pinnedVersion = $metadata['sha256_version'] ?? $metadata['latest_version'] ?? null;
        $pinnedAsset = $metadata['sha256_asset'] ?? $metadata['checksum_for_asset'] ?? null;
        if ($pinnedAsset === null) {
            $releaseAsset = $metadata['release_asset'] ?? null;
            if (is_string($releaseAsset) && ! strpbrk($releaseAsset, '*?[')) {
                $pinnedAsset = $releaseAsset;
            }
        }

        if (! is_string($pinnedVersion)
            || ! is_string($pinnedAsset)
            || $this->versions->normalize($pinnedVersion) !== $selectedVersion
            || basename($pinnedAsset) !== $assetName) {
            return null;
        }

        return $this->checksum($checksum, 'Registry-pinned SHA-256 checksum is malformed.');
    }

    private function structured(mixed $checksums, string $selectedVersion, string $assetName): ?string
    {
        if (! is_array($checksums)) {
            return null;
        }

        if (array_is_list($checksums)) {
            foreach ($checksums as $record) {
                if (! is_array($record)) {
                    continue;
                }
                $version = $record['version'] ?? null;
                $asset = $record['asset'] ?? $record['name'] ?? null;
                if (is_string($version)
                    && is_string($asset)
                    && $this->versions->normalize($version) === $selectedVersion
                    && basename($asset) === $assetName) {
                    return $this->checksum($record['sha256'] ?? null, 'Registry checksum record is malformed.');
                }
            }

            return null;
        }

        $versionChecksums = $checksums[$selectedVersion] ?? $checksums['v'.$selectedVersion] ?? null;
        if (is_array($versionChecksums) && array_key_exists($assetName, $versionChecksums)) {
            return $this->checksum($versionChecksums[$assetName], 'Registry checksum map is malformed.');
        }

        return null;
    }

    private function checksum(mixed $value, string $message): string
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/i', trim($value))) {
            throw new ExtensionOperationFailed($message);
        }

        return strtolower(trim($value));
    }
}
