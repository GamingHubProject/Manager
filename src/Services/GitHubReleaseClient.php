<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Illuminate\Support\Facades\Cache;

final class GitHubReleaseClient
{
    public function __construct(
        private SafeExtensionHttpClient $http,
        private ExtensionUrlGuard $guard,
        private ReleaseVersionValidator $releaseVersions,
    ) {
    }

    /**
     * @return array{release: array, asset: array, version: string}
     */
    public function discover(
        string $repository,
        string $assetPattern,
        bool $allowPrereleases = false,
        bool $force = false,
    ): array {
        return $this->selectFromReleases(
            $this->releases($repository, $force),
            $assetPattern,
            $allowPrereleases,
        );
    }

    /**
     * Pure release-selection entry point used by focused tests.
     *
     * @return array{release: array, asset: array, version: string}
     */
    public function selectFromReleases(array $releases, string $assetPattern, bool $allowPrereleases = false): array
    {
        $candidates = [];

        foreach ($releases as $release) {
            if (! is_array($release) || ($release['draft'] ?? true) === true) {
                continue;
            }

            try {
                $version = $this->releaseVersions->releaseVersion($release);
            } catch (\Throwable) {
                continue;
            }

            if (! $allowPrereleases
                && (($release['prerelease'] ?? false) === true || $this->releaseVersions->isPrerelease($version))) {
                continue;
            }

            $asset = $this->selectAssetOrNull($release, $assetPattern);
            if ($asset === null) {
                continue;
            }

            $candidates[] = [
                'release' => $release,
                'asset' => $asset,
                'version' => $version,
            ];
        }

        if ($candidates === []) {
            throw new ExtensionOperationFailed('No compatible packaged GitHub Release asset is available.');
        }

        usort($candidates, function (array $left, array $right): int {
            $version = version_compare($right['version'], $left['version']);
            if ($version !== 0) {
                return $version;
            }

            $published = strcmp(
                (string) ($right['release']['published_at'] ?? ''),
                (string) ($left['release']['published_at'] ?? ''),
            );
            if ($published !== 0) {
                return $published;
            }

            return ((int) ($right['release']['id'] ?? 0)) <=> ((int) ($left['release']['id'] ?? 0));
        });

        return $candidates[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function releases(string $repository, bool $force = false): array
    {
        [$owner, $repo] = $this->guard->assertGithubRepository($repository);
        $key = $this->cacheKey($owner, $repo);
        if ($force) {
            Cache::forget($key);
        } elseif (($cached = Cache::get($key)) !== null && is_array($cached)) {
            return $cached;
        }

        $releases = [];
        $pageLimit = max(1, min(20, (int) config('gaming-hub-manager.manager.github_release_page_limit', 10)));
        for ($page = 1; $page <= $pageLimit; $page++) {
            $batch = $this->http->json("https://api.github.com/repos/{$owner}/{$repo}/releases?per_page=100&page={$page}");
            foreach ($batch as $release) {
                if (is_array($release)) {
                    $releases[] = $release;
                }
            }
            if (count($batch) < 100) {
                break;
            }
        }

        Cache::put($key, $releases, (int) config('gaming-hub-manager.manager.release_cache_ttl', 300));

        return $releases;
    }

    public function invalidate(string $repository): void
    {
        [$owner, $repo] = $this->guard->assertGithubRepository($repository);
        Cache::forget($this->cacheKey($owner, $repo));
    }

    public function selectAsset(array $release, string $pattern): array
    {
        $asset = $this->selectAssetOrNull($release, $pattern);
        if ($asset === null) {
            throw new ExtensionOperationFailed('No matching packaged ZIP release asset found.');
        }

        return $asset;
    }

    /**
     * @return array{asset: array, allow_bare_hash: bool}|null
     */
    public function selectChecksumAsset(array $release, array $selectedAsset, ?string $preferred): ?array
    {
        $selectedName = $selectedAsset['name'] ?? null;
        if (! is_string($selectedName) || trim($selectedName) === '') {
            throw new ExtensionOperationFailed('Selected package asset name is missing.');
        }

        $assets = array_values(array_filter(
            $release['assets'] ?? [],
            static fn (mixed $asset): bool => is_array($asset),
        ));

        $priorities = [];
        if (is_string($preferred) && trim($preferred) !== '') {
            $priorities[] = ['name' => trim($preferred), 'allow_bare_hash' => strcasecmp(trim($preferred), $selectedName.'.sha256') === 0];
        }
        $priorities[] = ['name' => $selectedName.'.sha256', 'allow_bare_hash' => true];
        foreach (['SHA256SUMS', 'SHA256SUMS.txt', 'checksums.txt', 'checksum.txt', 'sha256sums.txt', 'sha256.txt'] as $name) {
            $priorities[] = ['name' => $name, 'allow_bare_hash' => false];
        }

        $seen = [];
        foreach ($priorities as $priority) {
            $needle = strtolower($priority['name']);
            if (isset($seen[$needle])) {
                continue;
            }
            $seen[$needle] = true;
            foreach ($assets as $asset) {
                $name = $asset['name'] ?? null;
                if (is_string($name) && strtolower($name) === $needle) {
                    return [
                        'asset' => $asset,
                        'allow_bare_hash' => $priority['allow_bare_hash'],
                    ];
                }
            }
        }

        return null;
    }

    private function selectAssetOrNull(array $release, string $pattern): ?array
    {
        foreach (($release['assets'] ?? []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $name = $asset['name'] ?? null;
            if (! is_string($name)
                || ! str_ends_with(strtolower($name), '.zip')
                || ! $this->matches($pattern, $name)
                || (isset($asset['state']) && $asset['state'] !== 'uploaded')) {
                continue;
            }

            if (! isset($asset['id']) || ! is_string($asset['browser_download_url'] ?? null)) {
                continue;
            }

            return $asset;
        }

        return null;
    }

    private function matches(string $pattern, string $name): bool
    {
        return fnmatch(strtolower($pattern), strtolower($name));
    }

    private function cacheKey(string $owner, string $repo): string
    {
        return 'gaminghub-manager:github-releases:'.strtolower($owner.'/'.$repo);
    }
}
