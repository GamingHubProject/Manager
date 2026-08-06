<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;

final class GitHubAssetDigestValidator
{
    public function resolve(array $asset): ?string
    {
        if (! array_key_exists('digest', $asset) || $asset['digest'] === null || $asset['digest'] === '') {
            return null;
        }

        $name = $asset['name'] ?? null;
        $id = $asset['id'] ?? null;
        if (! is_string($name) || trim($name) === '' || (! is_int($id) && ! is_string($id)) || trim((string) $id) === '') {
            throw new ExtensionOperationFailed('GitHub release asset digest is not bound to a valid asset identity.');
        }

        $digest = $asset['digest'];
        if (! is_string($digest) || ! preg_match('/^([A-Za-z0-9_-]+):(.+)$/', trim($digest), $matches)) {
            throw new ExtensionOperationFailed('GitHub release asset digest is malformed.');
        }

        if (strtolower($matches[1]) !== 'sha256') {
            throw new ExtensionOperationFailed('GitHub release asset digest uses an unsupported algorithm.');
        }

        $checksum = strtolower($matches[2]);
        if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new ExtensionOperationFailed('GitHub release asset SHA-256 digest is malformed.');
        }

        return $checksum;
    }
}
