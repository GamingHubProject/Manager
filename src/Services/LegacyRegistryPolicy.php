<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

/**
 * Narrow compatibility policy for Manager/Core registry metadata that predates
 * the GamingHubProject registry ownership boundary.
 *
 * Historical identifiers remain isolated here only so genuine upgraded
 * installations can be recognized. They are never used as defaults/fallbacks.
 */
final class LegacyRegistryPolicy
{
    public const LEGACY_MANAGER_OFFICIAL_SOURCE_ID = 'rosesofdorns-official';

    /** @var list<string> */
    private const LEGACY_OFFICIAL_URLS = [
        'https://raw.githubusercontent.com/RosesOfDorns/gaming-hub-registry/main/registry.json',
        'https://raw.githubusercontent.com/RosesOfDorns/gaming-hub-community/main/registry.json',
    ];

    public function isCanonicalOfficialUrl(string $url): bool
    {
        return $this->sameUrl($url, ExtensionSourceManager::OFFICIAL_URL);
    }

    public function isLegacyOfficialUrl(string $url): bool
    {
        foreach (self::LEGACY_OFFICIAL_URLS as $legacyUrl) {
            if ($this->sameUrl($url, $legacyUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A legacy Core source row is suppressed only when it is positively
     * identifiable as the historical official source. Custom legacy sources,
     * including arbitrary GitHub registries, continue through normal import.
     *
     * @param array<string, mixed> $values
     */
    public function suppressLegacyImport(array $values): bool
    {
        $type = strtolower($this->scalar($values['type'] ?? null));
        $sourceId = strtolower($this->scalar($values['source_id'] ?? null));
        $name = $this->scalar($values['name'] ?? null);
        $url = $this->scalar($values['url'] ?? null);
        $officialUrl = $this->isCanonicalOfficialUrl($url) || $this->isLegacyOfficialUrl($url);

        if ($sourceId === self::LEGACY_MANAGER_OFFICIAL_SOURCE_ID && $officialUrl) {
            return true;
        }

        if ($type === 'official' && $officialUrl) {
            return true;
        }

        return $this->isImportedOfficialArtifact($name, $url);
    }

    /**
     * Identify only the exact artifact shape produced by Manager's historical
     * Core importer. This is intentionally stricter than URL/name matching
     * alone so administrator-created custom registries are preserved.
     *
     * @param array<string, mixed> $values
     */
    public function isObsoleteManagedStoredSource(array $values): bool
    {
        $type = strtolower($this->scalar($values['type'] ?? null));
        $sourceId = strtolower($this->scalar($values['source_id'] ?? null));
        $name = $this->scalar($values['name'] ?? null);
        $url = $this->scalar($values['url'] ?? null);

        if ($type === 'official') {
            return true;
        }

        if ($sourceId === self::LEGACY_MANAGER_OFFICIAL_SOURCE_ID && $this->isLegacyOfficialUrl($url)) {
            return true;
        }

        return $this->isImportedOfficialArtifact($name, $url);
    }

    private function isImportedOfficialArtifact(string $name, string $url): bool
    {
        if (! str_ends_with($name, ' (imported from Core)')) {
            return false;
        }

        return $this->isCanonicalOfficialUrl($url) || $this->isLegacyOfficialUrl($url);
    }

    private function sameUrl(string $left, string $right): bool
    {
        return hash_equals($this->normalizeUrl($right), $this->normalizeUrl($left));
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $portPart = $port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))
            ? ':'.$port
            : '';
        $path = preg_replace('#/+#', '/', (string) ($parts['path'] ?? '/')) ?: '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        // Official registry URLs are public immutable locations. Query strings
        // and fragments are formatting/cache-busting differences, not ownership.
        return $scheme.'://'.$host.$portPart.$path;
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
