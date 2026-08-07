<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\NormalizedRegistry;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ExtensionSourceManager
{
    public function __construct(
        private SafeExtensionHttpClient $http,
        private ExtensionRegistryValidator $validator,
        private GitHubReleaseClient $github,
        private ExtensionSafeMessage $messages,
        private LegacyRegistryPolicy $legacyRegistry,
    ) {
    }

    public const OFFICIAL_SOURCE_ID = 'gaminghubproject-official';
    public const OFFICIAL_NAME = 'GamingHubProject Official Registry';
    public const OFFICIAL_URL = 'https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json';

    public function ensureOfficial(): ExtensionSource
    {
        $sources = ExtensionSource::query()->orderBy('id')->get();
        $source = $sources->first(fn (ExtensionSource $candidate) =>
            $candidate->source_id === self::OFFICIAL_SOURCE_ID && $candidate->type === 'official'
        );

        if ($source === null) {
            $source = $sources->first(fn (ExtensionSource $candidate) => $candidate->type === 'official');
        }
        if ($source === null) {
            $source = new ExtensionSource();
        }

        foreach ($sources as $candidate) {
            if ($source->exists && $candidate->getKey() === $source->getKey()) {
                continue;
            }

            if (! $this->legacyRegistry->isObsoleteManagedStoredSource($candidate->toArray())) {
                continue;
            }

            Cache::forget($this->cacheKey($candidate));
            $candidate->delete();
        }

        $sourceIdInUse = ExtensionSource::query()
            ->where('source_id', self::OFFICIAL_SOURCE_ID)
            ->when($source->exists, fn ($query) => $query->where($source->getKeyName(), '!=', $source->getKey()))
            ->exists();

        $values = [
            'type' => 'official',
            'name' => self::OFFICIAL_NAME,
            'url' => self::OFFICIAL_URL,
            'trust_level' => 'official',
            'trusted' => true,
            'enabled' => true,
        ];
        if (! $sourceIdInUse) {
            $values['source_id'] = self::OFFICIAL_SOURCE_ID;
        } elseif (! $source->exists || ! str_starts_with((string) $source->source_id, self::OFFICIAL_SOURCE_ID.'-managed-')) {
            // Preserve an unexpected administrator-owned source_id collision.
            // The official source gets one stable protected Manager-owned ID.
            $values['source_id'] = $this->availableOfficialSourceId();
        }

        $source->forceFill($values)->save();

        return $source;
    }

    public function isOfficialRegistryUrl(string $url): bool
    {
        return $this->legacyRegistry->isCanonicalOfficialUrl($url);
    }

    private function availableOfficialSourceId(): string
    {
        $suffix = 1;
        do {
            $candidate = self::OFFICIAL_SOURCE_ID.'-managed-'.$suffix++;
        } while (ExtensionSource::query()->where('source_id', $candidate)->exists());

        return $candidate;
    }

    public function refresh(ExtensionSource $source, bool $force = false): array
    {
        $key = $this->cacheKey($source);
        $cached = Cache::get($key);
        if (! $force && is_array($cached)) {
            return $cached;
        }

        if ($force) {
            $this->invalidateReleaseMetadata($source, is_array($cached) ? $cached : null);
            Cache::forget($key);
        }

        try {
            $allowPrivate = $source->allow_private_host
                && (bool) config('gaming-hub-manager.manager.allow_private_hosts', false);

            if ($source->type === 'github') {
                $pattern = (string) ($source->metadata['release_asset'] ?? '*.zip');
                $selection = $this->github->discover(
                    $source->url,
                    $pattern,
                    $source->allow_prereleases,
                    $force,
                );
                $data = ['kind' => 'github', ...$selection];
            } else {
                $raw = $this->http->json($source->url, $allowPrivate);
                $registry = $this->validator->validate($raw, $source->type === 'official', $allowPrivate);
                if ($force) {
                    $this->invalidateRegistryReleases($registry);
                }
                $data = [
                    'kind' => 'registry',
                    'registry' => $registry,
                ];
            }

            Cache::put($key, $data, (int) config('gaming-hub-manager.manager.registry_cache_ttl', 300));
            $source->forceFill(['last_successful_refresh_at' => now(), 'last_error' => null])->save();

            return $data;
        } catch (\Throwable $exception) {
            $message = $this->messages->fromThrowable($exception);
            $source->forceFill(['last_error' => $message])->save();

            if (is_array($cached)) {
                return $cached + ['stale' => true, 'error' => $message];
            }

            if ($source->type === 'official') {
                $fallback = $this->loadOfficialFallback();
                $registry = $this->validator->validate($fallback, true, false);
                if ($force) {
                    $this->invalidateRegistryReleases($registry);
                }
                $data = [
                    'kind' => 'registry',
                    'registry' => $registry,
                    'fallback' => true,
                    'error' => $message,
                ];
                Cache::put($key, $data, (int) config('gaming-hub-manager.manager.registry_cache_ttl', 300));

                return $data;
            }

            throw $exception;
        }
    }

    public function invalidate(ExtensionSource $source): void
    {
        $key = $this->cacheKey($source);
        $cached = Cache::get($key);
        $this->invalidateReleaseMetadata($source, is_array($cached) ? $cached : null);
        Cache::forget($key);
    }

    public function makeId(string $name): string
    {
        return 'custom-'.Str::slug($name).'-'.Str::lower(Str::random(6));
    }

    private function invalidateReleaseMetadata(ExtensionSource $source, ?array $cached): void
    {
        if ($source->type === 'github') {
            $this->github->invalidate($source->url);

            return;
        }

        $registry = $cached['registry'] ?? null;
        if ($registry instanceof NormalizedRegistry) {
            $this->invalidateRegistryReleases($registry);
        }
    }

    private function invalidateRegistryReleases(NormalizedRegistry $registry): void
    {
        $repositories = [];
        foreach ($registry->extensions as $entry) {
            $repositories[$entry->repository] = true;
        }
        foreach (array_keys($repositories) as $repository) {
            $this->github->invalidate($repository);
        }
    }

    private function cacheKey(ExtensionSource $source): string
    {
        return 'gaminghub-manager:source:'.$source->id;
    }

    private function loadOfficialFallback(): array
    {
        $path = (string) config('gaming-hub-manager.manager.official_registry_fallback');
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($decoded)) {
            throw new \RuntimeException('The bundled official registry fallback is unavailable.');
        }

        return $decoded;
    }
}
