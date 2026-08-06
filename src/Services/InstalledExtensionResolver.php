<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;

final class InstalledExtensionResolver
{
    public function __construct(
        private ExtensionPathGuard $paths,
        private ExtensionManifestValidator $manifests,
        private AzuriomPluginLifecycle $lifecycle,
        private DirectoryHasher $hasher,
    ) {
    }

    public function reconcileFilesystem(): void
    {
        $entries = scandir($this->paths->pluginsRoot()) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $this->paths->pluginsRoot().DIRECTORY_SEPARATOR.$entry;
            $known = InstalledExtension::where('extension_id', $entry)->exists();
            $hasManagerManifest = is_file($path.'/gaming-hub-extension.json');
            if (! $known && ! $hasManagerManifest && ! str_starts_with($entry, 'gaming-hub-')) {
                continue;
            }

            try {
                $this->resolve($entry, true, false);
            } catch (\Throwable) {
                // Unrelated, incomplete, or malformed plugin directories are ignored.
            }
        }
    }

    public function resolve(string $extensionId, bool $createMetadata = true, bool $verifyIntegrity = true): InstalledExtension
    {
        $extensionId = $this->paths->validateId($extensionId);
        $record = InstalledExtension::where('extension_id', $extensionId)->first();
        $path = $this->paths->destination($extensionId);
        if (! is_dir($path)) {
            if ($record !== null) {
                return $record;
            }
            throw new ExtensionOperationFailed('Installed package metadata and files were not found.');
        }

        $manifest = $this->readManifest($path, $extensionId);
        if ($record === null && ! $createMetadata) {
            throw new ExtensionOperationFailed('Installed package metadata was not found.');
        }

        $snapshot = $this->preserveRegistryContract(
            $manifest->toArray(),
            $record?->manifest_snapshot,
            is_file($path.'/gaming-hub-extension.json'),
        );
        $values = [
            'installed_version' => $manifest->version,
            'enabled_snapshot' => $this->lifecycle->isEnabled($extensionId),
            'manifest_snapshot' => $snapshot,
            'last_operation_result' => $record?->last_operation_result ?? 'discovered',
        ];

        if ($record === null || $verifyIntegrity || $record->integrity_hash === null) {
            $integrity = $this->hasher->hash($path);
            $values += [
                'integrity_hash' => $record?->integrity_hash ?? $integrity,
                'integrity_status' => $record?->integrity_hash === null || hash_equals((string) $record->integrity_hash, $integrity) ? 'verified' : 'changed',
                'integrity_checked_at' => now(),
            ];
        }

        if ($record === null) {
            $values += [
                'source_type' => 'local',
                'source_id' => 'filesystem',
                'repository_url' => $manifest->repository,
                'trust_level' => str_starts_with($extensionId, 'gaming-hub-') ? 'local' : 'untrusted',
                'installed_at' => now(),
                'checksum_verified' => false,
            ];
        }

        return InstalledExtension::updateOrCreate(['extension_id' => $extensionId], $values);
    }


    private function preserveRegistryContract(array $normalized, ?array $existing, bool $hasPackageManifest): array
    {
        if ($hasPackageManifest || ! is_array($existing)) {
            return $normalized;
        }

        foreach (['requires', 'provides', 'consumes', 'type', 'repository', 'homepage', 'public_attribution_label'] as $key) {
            if (array_key_exists($key, $existing)) {
                $normalized[$key] = $existing[$key];
            }
        }

        return $normalized;
    }

    public function readManifest(string $path, string $expectedId): ExtensionManifest
    {
        if (! is_file($path.'/plugin.json')) {
            throw new ExtensionOperationFailed('Installed package is missing plugin.json.');
        }

        $plugin = json_decode((string) file_get_contents($path.'/plugin.json'), true);
        $manifest = is_file($path.'/gaming-hub-extension.json')
            ? json_decode((string) file_get_contents($path.'/gaming-hub-extension.json'), true)
            : null;
        if (! is_array($plugin) || ($manifest !== null && ! is_array($manifest))) {
            throw new ExtensionOperationFailed('Installed package metadata JSON is invalid.');
        }

        $normalized = $this->manifests->validate(
            $manifest,
            $plugin,
            null,
            $expectedId === 'gaming-hub-manager',
        );
        if ($normalized->id !== $expectedId || $normalized->pluginDirectory !== $expectedId) {
            throw new ExtensionOperationFailed('Installed package ID does not match its directory.');
        }

        return $normalized;
    }
}
