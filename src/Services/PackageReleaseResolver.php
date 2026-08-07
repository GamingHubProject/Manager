<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;

final class PackageReleaseResolver
{
    public function __construct(
        private ExtensionSourceManager $sources,
        private GitHubReleaseClient $github,
        private ExtensionChecksumVerifier $checksums,
        private GitHubAssetDigestValidator $githubDigests,
        private RegistryChecksumResolver $registryChecksums,
        private SafeExtensionHttpClient $http,
    ) {
    }

    /**
     * @return array{
     *     release: array,
     *     asset: array,
     *     checksum: string,
     *     checksumSource: string,
     *     selectedVersion: string,
     *     metadata: array,
     *     checksumAsset: ?array
     * }
     */
    public function resolve(ExtensionSource $source, string $packageId, ?ExtensionOperation $operation = null): array
    {
        $operation?->transition('resolving', 'Resolving the selected source release.');
        $data = $this->sources->refresh($source, true);
        $metadata = [];

        if ($source->type === 'github') {
            $release = $data['release'];
            $asset = $data['asset'];
            $selectedVersion = (string) $data['version'];
            $metadata = [
                'id' => $packageId === 'direct' ? null : $packageId,
                'repository' => $source->url,
                'release_asset' => $source->metadata['release_asset'] ?? '*.zip',
                'checksum_asset' => $source->metadata['checksum_asset'] ?? null,
            ];
        } else {
            $registry = $data['registry'];
            $entry = collect($registry->extensions)->first(fn ($candidate) => $candidate->id === $packageId);
            if ($entry === null) {
                throw new ExtensionOperationFailed('Requested package is not present in the selected registry.');
            }

            $metadata = $entry->raw;
            $selection = $this->github->discover(
                $entry->repository,
                $entry->releaseAsset,
                $source->allow_prereleases || (bool) ($entry->raw['allow_prereleases'] ?? false),
                true,
            );
            $release = $selection['release'];
            $asset = $selection['asset'];
            $selectedVersion = $selection['version'];
        }

        $assetName = $asset['name'] ?? null;
        if (! is_string($assetName) || trim($assetName) === '') {
            throw new ExtensionOperationFailed('Selected GitHub package asset name is missing.');
        }

        $metadata['selected_version'] = $selectedVersion;
        $metadata['selected_asset_name'] = $assetName;
        $metadata['selected_asset_id'] = $asset['id'] ?? null;
        $metadata['release_tag'] = $release['tag_name'] ?? null;

        $checksum = null;
        $checksumSource = null;
        $checksumAsset = null;
        $preferredChecksumAsset = isset($metadata['checksum_asset']) && is_string($metadata['checksum_asset'])
            ? $metadata['checksum_asset']
            : null;
        $explicit = $this->github->selectChecksumAsset($release, $asset, $preferredChecksumAsset);

        if ($explicit !== null) {
            $checksumAsset = $explicit['asset'];
            $checksumUrl = $checksumAsset['browser_download_url'] ?? $checksumAsset['url'] ?? null;
            if (! is_string($checksumUrl) || $checksumUrl === '') {
                throw new ExtensionOperationFailed('Selected checksum asset has no valid download URL.');
            }
            $text = $this->http->text($checksumUrl, $source->allow_private_host);
            $checksum = $this->checksums->parse($text, $assetName, $explicit['allow_bare_hash']);
            if ($checksum === null) {
                throw new ExtensionOperationFailed('Checksum asset did not contain a SHA-256 entry for the selected ZIP.');
            }
            $checksumSource = 'explicit_checksum_asset';
        } else {
            $checksum = $this->githubDigests->resolve($asset);
            if ($checksum !== null) {
                $checksumSource = 'github_asset_digest';
            } else {
                $checksum = $this->registryChecksums->resolve($metadata, $selectedVersion, $assetName);
                if ($checksum !== null) {
                    $checksumSource = 'registry_pinned';
                }
            }
        }

        if ($checksum === null || $checksumSource === null) {
            throw new ExtensionOperationFailed('No valid published SHA-256 checksum source exists for the selected package asset.');
        }

        $checksum = $this->checksums->normalize($checksum);
        if ($operation !== null) {
            $operation->mergeContext([
                'release_tag' => $release['tag_name'] ?? null,
                'release_version' => $selectedVersion,
                'package_asset_id' => isset($asset['id']) ? (string) $asset['id'] : null,
                'package_asset_name' => $assetName,
                'checksum_source' => $checksumSource,
                'checksum_asset_id' => isset($checksumAsset['id']) ? (string) $checksumAsset['id'] : null,
                'checksum_asset_name' => $checksumAsset['name'] ?? null,
            ]);
            $operation->appendEvent(
                'resolving',
                'Selected GitHub Release '.$selectedVersion.' and checksum source '.$checksumSource.'.',
            );
            $operation->save();
        }

        return [
            'release' => $release,
            'asset' => $asset,
            'checksum' => $checksum,
            'checksumSource' => $checksumSource,
            'selectedVersion' => $selectedVersion,
            'metadata' => $metadata,
            'checksumAsset' => $checksumAsset,
        ];
    }
}
