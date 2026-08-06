<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\NormalizedRegistry;
use Azuriom\Plugin\GamingHubManager\Data\RegistryExtension;
use Azuriom\Plugin\GamingHubManager\Exceptions\InvalidExtensionRegistry;

final class ExtensionRegistryValidator
{
    public function __construct(private ExtensionUrlGuard $urls)
    {
    }

    public function validate(array $registry, bool $official = false, bool $allowPrivate = false): NormalizedRegistry
    {
        if (($registry['schema'] ?? null) !== 1) {
            throw new InvalidExtensionRegistry('Unsupported registry schema.');
        }

        $id = $this->id($registry['id'] ?? null);
        $name = $this->text($registry['name'] ?? null, 150, 'registry name');
        $homepage = isset($registry['homepage']) ? $this->url($registry['homepage'], $allowPrivate) : null;
        $seen = [];
        $items = [];

        foreach (($registry['extensions'] ?? []) as $entry) {
            if (! is_array($entry)) {
                throw new InvalidExtensionRegistry('Invalid extension entry.');
            }

            $extensionId = $this->id($entry['id'] ?? null);
            if (isset($seen[$extensionId])) {
                throw new InvalidExtensionRegistry('Duplicate extension ID: '.$extensionId);
            }
            $seen[$extensionId] = true;

            $repository = (string) ($entry['repository'] ?? '');
            $this->urls->assertGithubRepository($repository, $allowPrivate);
            $latestVersion = null;
            if (array_key_exists('latest_version', $entry) && $entry['latest_version'] !== null && $entry['latest_version'] !== '') {
                $latestVersion = ltrim((string) $entry['latest_version'], 'vV');
                if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?(?:\+[0-9A-Za-z][0-9A-Za-z.-]*)?$/', $latestVersion)) {
                    throw new InvalidExtensionRegistry('Invalid legacy latest_version hint for '.$extensionId.'.');
                }
            }

            $releaseAsset = $this->text($entry['release_asset'] ?? null, 255, 'release asset');
            if (! str_ends_with(strtolower(str_replace('*', 'x', $releaseAsset)), '.zip')) {
                throw new InvalidExtensionRegistry('Release asset must target ZIP packages.');
            }

            $items[] = new RegistryExtension(
                $extensionId,
                $this->text($entry['name'] ?? null, 150, 'name'),
                $this->text($entry['description'] ?? '', 1000, 'description', true),
                $this->text($entry['author'] ?? '', 150, 'author', true),
                $this->text($entry['category'] ?? 'Other', 80, 'category'),
                $repository,
                $releaseAsset,
                isset($entry['checksum_asset']) ? $this->text($entry['checksum_asset'], 255, 'checksum asset') : null,
                $latestVersion,
                (bool) ($entry['verified'] ?? false),
                $official && (bool) ($entry['official'] ?? false),
                isset($entry['icon']) ? $this->url($entry['icon'], $allowPrivate) : null,
                isset($entry['release_notes_url']) ? $this->url($entry['release_notes_url'], $allowPrivate) : null,
                $entry,
            );
        }

        return new NormalizedRegistry(1, $id, $name, $homepage, $items, now()->toIso8601String());
    }

    private function id(mixed $value): string
    {
        $value = (string) $value;
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,98}[a-z0-9]$/', $value)) {
            throw new InvalidExtensionRegistry('Invalid ID.');
        }

        return $value;
    }

    private function text(mixed $value, int $max, string $field, bool $allowEmpty = false): string
    {
        $value = trim((string) $value);
        if ((! $allowEmpty && $value === '') || mb_strlen($value) > $max || preg_match('/<[^>]+>/', $value)) {
            throw new InvalidExtensionRegistry('Invalid '.$field.'.');
        }

        return $value;
    }

    private function url(mixed $value, bool $allowPrivate): string
    {
        $value = (string) $value;
        $this->urls->assertSafe($value, $allowPrivate);

        return $value;
    }
}
