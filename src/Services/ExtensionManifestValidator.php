<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\InvalidExtensionManifest;

final class ExtensionManifestValidator
{
    private const TYPES = ['core', 'integration', 'game-integration', 'feature', 'utility'];

    public function validate(
        ?array $manifest,
        array $plugin,
        ?array $registryMetadata = null,
        bool $allowManagerInspection = false,
    ): ExtensionManifest {
        $manifest ??= [];
        $registryMetadata ??= [];

        if ($manifest !== [] && ($manifest['schema'] ?? null) !== 1) {
            throw new InvalidExtensionManifest('Unsupported package manifest schema.');
        }

        $id = $this->canonicalId($manifest, $plugin, $registryMetadata);
        if ($id === 'gaming-hub-manager' && ! $allowManagerInspection) {
            throw new InvalidExtensionManifest('Gaming Hub Manager cannot manage or replace itself.');
        }

        $version = (string) ($manifest['version'] ?? $plugin['version'] ?? '');
        if (($plugin['version'] ?? null) !== $version) {
            throw new InvalidExtensionManifest('Package manifest and plugin version mismatch.');
        }
        if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new InvalidExtensionManifest('Invalid package version.');
        }

        $type = (string) ($manifest['type'] ?? $registryMetadata['type'] ?? ($id === 'gaming-hub-core' ? 'core' : 'utility'));
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidExtensionManifest('Unsupported package type.');
        }

        $requirements = $this->requirements($manifest, $plugin, $registryMetadata);
        $package = is_array($manifest['package'] ?? null) ? $manifest['package'] : [];
        $directory = $this->id($package['plugin_directory'] ?? $id);
        if ($directory !== $id) {
            throw new InvalidExtensionManifest('Package identity mismatch: plugin_directory must match the canonical package ID.');
        }

        $algorithm = strtolower((string) ($package['checksum_algorithm'] ?? 'sha256'));
        if ($algorithm !== 'sha256') {
            throw new InvalidExtensionManifest('Only SHA-256 is supported.');
        }

        $provides = $this->declarations($manifest['provides'] ?? []);
        $consumes = $this->declarations($manifest['consumes'] ?? []);
        $repository = $manifest['repository'] ?? $registryMetadata['repository'] ?? $plugin['url'] ?? null;
        $homepage = $manifest['homepage'] ?? $registryMetadata['homepage'] ?? $plugin['url'] ?? null;

        $raw = array_replace_recursive([
            'schema' => 1,
            'id' => $id,
            'name' => (string) ($plugin['name'] ?? $registryMetadata['name'] ?? $id),
            'version' => $version,
            'type' => $type,
            'description' => (string) ($plugin['description'] ?? $registryMetadata['description'] ?? ''),
            'author' => $this->pluginAuthor($plugin, $registryMetadata),
            'homepage' => $homepage,
            'repository' => $repository,
            'requires' => $requirements,
            'provides' => $provides,
            'consumes' => $consumes,
            'package' => [
                'plugin_directory' => $directory,
                'checksum_algorithm' => $algorithm,
            ],
        ], $manifest);

        return new ExtensionManifest(
            1,
            $id,
            $this->text($raw['name'] ?? '', 150),
            $version,
            $type,
            $this->text($raw['description'] ?? '', 1000),
            $this->text($raw['author'] ?? '', 150),
            is_string($homepage) ? $homepage : null,
            is_string($repository) ? $repository : null,
            $requirements,
            $provides,
            $consumes,
            $directory,
            $algorithm,
            isset($raw['public_attribution_label']) ? $this->text($raw['public_attribution_label'], 150) : null,
            $raw,
        );
    }

    private function canonicalId(array $manifest, array $plugin, array $registryMetadata): string
    {
        $declared = [
            'plugin.json' => $plugin['id'] ?? null,
            'gaming-hub-extension.json' => $manifest['id'] ?? null,
            'registry' => $registryMetadata['id'] ?? null,
        ];
        $canonical = null;
        $canonicalSource = null;

        foreach ($declared as $source => $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value)) {
                throw new InvalidExtensionManifest('Invalid package identifier in '.$source.'.');
            }

            $candidate = $this->id($value);
            if ($canonical === null) {
                $canonical = $candidate;
                $canonicalSource = $source;
                continue;
            }

            if ($candidate !== $canonical) {
                throw new InvalidExtensionManifest(
                    'Package identity mismatch: '.$source.' declares '.$candidate
                    .' but '.$canonicalSource.' declares '.$canonical.'.',
                );
            }
        }

        if ($canonical === null) {
            throw new InvalidExtensionManifest('Missing canonical package identifier.');
        }

        return $canonical;
    }

    private function requirements(array $manifest, array $plugin, array $registryMetadata): array
    {
        $requirements = [];
        foreach ([$registryMetadata['requires'] ?? [], $manifest['requires'] ?? []] as $candidate) {
            if (is_array($candidate)) {
                $requirements = array_replace_recursive($requirements, $candidate);
            }
        }

        $api = (string) ($plugin['azuriom_api'] ?? '1.2.0');
        $requirements['azuriom'] = is_string($requirements['azuriom'] ?? null)
            ? trim($requirements['azuriom'])
            : '>='.ltrim($api, 'vV');
        $requirements['php'] = is_string($requirements['php'] ?? null)
            ? trim($requirements['php'])
            : '>=8.2.0';

        if (isset($requirements['gaming-hub-core']) && ! is_string($requirements['gaming-hub-core'])) {
            throw new InvalidExtensionManifest('Invalid Gaming Hub Core version constraint.');
        }

        $extensions = is_array($requirements['extensions'] ?? null) ? $requirements['extensions'] : [];
        foreach (($plugin['dependencies'] ?? []) as $dependency => $constraint) {
            if (! is_string($dependency) || ! is_string($constraint)) {
                continue;
            }
            $optional = str_ends_with($dependency, '?');
            $dependency = rtrim($dependency, '?');
            if (! $optional && $dependency !== '') {
                $extensions[$dependency] ??= $constraint;
            }
        }
        foreach ($extensions as $dependency => $constraint) {
            if (! is_string($dependency) || ! is_string($constraint) || $dependency === '' || $constraint === '') {
                throw new InvalidExtensionManifest('Invalid package dependency.');
            }
            $this->id($dependency);
        }
        $requirements['extensions'] = $extensions;

        return $requirements;
    }

    private function pluginAuthor(array $plugin, array $registryMetadata): string
    {
        $authors = $plugin['authors'] ?? [];
        if (is_array($authors) && isset($authors[0])) {
            if (is_string($authors[0])) {
                return $authors[0];
            }
            if (is_array($authors[0]) && is_string($authors[0]['name'] ?? null)) {
                return $authors[0]['name'];
            }
        }

        return (string) ($registryMetadata['author'] ?? 'Unknown');
    }

    private function id(mixed $value): string
    {
        $value = (string) $value;
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,98}[a-z0-9]$/', $value)) {
            throw new InvalidExtensionManifest('Invalid package identifier.');
        }

        return $value;
    }

    private function text(mixed $value, int $max): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $max || preg_match('/<[^>]+>/', $value)) {
            throw new InvalidExtensionManifest('Invalid package text field.');
        }

        return $value;
    }

    private function declarations(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidExtensionManifest('Invalid package declarations.');
        }

        foreach ($value as $group => $items) {
            if (! is_string($group) || ! is_array($items)) {
                throw new InvalidExtensionManifest('Invalid declaration group.');
            }
            if (count($items) !== count(array_unique($items, SORT_REGULAR))) {
                throw new InvalidExtensionManifest('Duplicate package declaration.');
            }
            foreach ($items as $item) {
                if (! is_string($item) || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,127}$/', $item)) {
                    throw new InvalidExtensionManifest('Invalid package declaration value.');
                }
            }
        }

        return $value;
    }
}
