<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;
use Illuminate\Support\Collection;

final class PackageCatalog
{
    public function __construct(
        private ExtensionSourceManager $sources,
        private GitHubReleaseClient $github,
        private ExtensionVersionPolicy $versions,
        private ExtensionSafeMessage $messages,
        private InstalledExtensionResolver $installedResolver,
        private ManagerSchema $schema,
    ) {
    }

    /**
     * @return array{
     *     sources: Collection<int, ExtensionSource>,
     *     installed: Collection<int, InstalledExtension>,
     *     items: list<array<string, mixed>>,
     *     updates: array<string, array<string, mixed>>,
     *     managerAlerts: list<array{level: string, label: string, message: string}>
     * }
     */
    public function snapshot(bool $force = false): array
    {
        $status = $this->schema->status();
        if (! $status['schema_ready']) {
            return [
                'sources' => collect(),
                'installed' => collect(),
                'items' => [],
                'updates' => [],
                'managerAlerts' => [[
                    'level' => 'warning',
                    'label' => 'Migrations required',
                    'message' => 'Gaming Hub Manager package metadata is unavailable until its migrations are complete.',
                ]],
            ];
        }

        // Registry data is compared only after the installed set has been rebuilt
        // from canonical package directories/manifests.
        $this->installedResolver->reconcileFilesystem();

        $sources = ExtensionSource::query()
            ->orderByRaw("CASE WHEN type = 'official' THEN 0 WHEN type = 'registry' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();
        $installed = InstalledExtension::query()->orderBy('extension_id')->get();
        $installedById = $installed->keyBy('extension_id');
        $items = [];
        $managerAlerts = [];
        $updates = [];

        foreach ($sources->where('enabled', true) as $source) {
            try {
                $data = $this->sources->refresh($source, $force);
                if (isset($data['registry'])) {
                    foreach ($data['registry']->extensions as $entry) {
                        // Canonical package ID is the only registry-to-install join key.
                        $package = $installedById->get($entry->id);
                        $selection = null;
                        $discoveryError = null;

                        try {
                            $selection = $this->github->discover(
                                $entry->repository,
                                $entry->releaseAsset,
                                $this->allowPrereleases($source, $entry->raw),
                            );
                        } catch (\Throwable $exception) {
                            $discoveryError = $this->messages->fromThrowable($exception);
                        }

                        if ($selection !== null) {
                            $latestVersion = $selection['version'];
                            $state = $this->state(
                                $latestVersion,
                                $package,
                                $entry->raw['requires'] ?? null,
                                $installedById,
                            );
                            $releaseDiscovery = 'github';
                        } elseif ($entry->latestVersion !== null) {
                            $latestVersion = $entry->latestVersion;
                            $state = $this->state(
                                $latestVersion,
                                $package,
                                $entry->raw['requires'] ?? null,
                                $installedById,
                            );
                            $releaseDiscovery = 'legacy_hint';
                            $managerAlerts[] = [
                                'level' => 'warning',
                                'label' => $entry->name,
                                'message' => 'GitHub release discovery was unavailable; deprecated registry latest_version was used as a temporary hint. '.$discoveryError,
                            ];
                        } else {
                            $latestVersion = 'Unavailable';
                            $state = 'unavailable';
                            $releaseDiscovery = 'unavailable';
                            $managerAlerts[] = [
                                'level' => 'warning',
                                'label' => $entry->name,
                                'message' => 'GitHub release discovery failed and the registry has no legacy version hint. '.$discoveryError,
                            ];
                        }

                        $item = [
                            'source' => $source,
                            'id' => $entry->id,
                            'name' => $entry->name,
                            'description' => $entry->description,
                            'author' => $entry->author,
                            'category' => $entry->category,
                            'repository' => $entry->repository,
                            'latest_version' => $latestVersion,
                            'registry_latest_hint' => $entry->latestVersion,
                            'release_asset' => $entry->releaseAsset,
                            'checksum_asset' => $entry->checksumAsset,
                            'verified' => $entry->verified,
                            'official' => $entry->official,
                            'metadata' => $entry->raw,
                            'installed' => $package,
                            'state' => $state,
                            'release_discovery' => $releaseDiscovery,
                            'selected_release' => $selection['release'] ?? null,
                            'selected_asset' => $selection['asset'] ?? null,
                            'fallback_registry' => (bool) ($data['fallback'] ?? false),
                        ];
                        $items[] = $item;

                        if ($state === 'update'
                            && (! isset($updates[$entry->id])
                                || $this->versions->compare($latestVersion, $updates[$entry->id]['latest_version']) > 0)) {
                            $updates[$entry->id] = $item;
                        }
                    }
                } elseif (isset($data['release'], $data['asset'], $data['version'])) {
                    // A direct source may retain a package association only through the
                    // exact source_id recorded when Manager installed it. Repository URL,
                    // display name and repository name never infer package identity.
                    $package = $installed->firstWhere('source_id', $source->source_id);
                    $latestVersion = (string) $data['version'];
                    $id = $package?->extension_id ?? 'direct';
                    $state = $package === null ? 'available' : $this->state($latestVersion, $package, null, $installedById);
                    $item = [
                        'source' => $source,
                        'id' => $id,
                        'name' => $source->name,
                        'description' => 'Direct GitHub Release source.',
                        'author' => parse_url($source->url, PHP_URL_PATH) ?: 'GitHub',
                        'category' => 'Direct GitHub',
                        'repository' => $source->url,
                        'latest_version' => $latestVersion,
                        'registry_latest_hint' => null,
                        'release_asset' => (string) ($source->metadata['release_asset'] ?? '*.zip'),
                        'checksum_asset' => $source->metadata['checksum_asset'] ?? null,
                        'verified' => $source->trusted,
                        'official' => false,
                        'metadata' => [
                            'id' => $id,
                            'repository' => $source->url,
                            'release_asset' => $source->metadata['release_asset'] ?? '*.zip',
                            'checksum_asset' => $source->metadata['checksum_asset'] ?? null,
                        ],
                        'installed' => $package,
                        'state' => $state,
                        'release_discovery' => 'github',
                        'selected_release' => $data['release'],
                        'selected_asset' => $data['asset'],
                        'fallback_registry' => false,
                    ];
                    $items[] = $item;
                    if ($state === 'update' && $package !== null) {
                        $updates[$package->extension_id] = $item;
                    }
                }
            } catch (\Throwable $exception) {
                $managerAlerts[] = [
                    'level' => 'warning',
                    'label' => $source->name,
                    'message' => $this->messages->sanitize($source->last_error ?: $exception->getMessage()),
                ];
            }
        }

        usort($items, fn (array $left, array $right) => [$left['category'], $left['name']] <=> [$right['category'], $right['name']]);

        return [
            'sources' => $sources,
            'installed' => $installed,
            'items' => $items,
            'updates' => $updates,
            'managerAlerts' => $managerAlerts,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForPackage(string $packageId): ?array
    {
        $snapshot = $this->snapshot(false);
        $candidates = array_values(array_filter(
            $snapshot['items'],
            fn (array $item) => $item['id'] === $packageId && $item['state'] !== 'unavailable',
        ));
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            $trust = ['official' => 3, 'trusted' => 2, 'untrusted' => 1];
            $leftTrust = $trust[$left['source']->trust_level] ?? 0;
            $rightTrust = $trust[$right['source']->trust_level] ?? 0;
            if ($leftTrust !== $rightTrust) {
                return $rightTrust <=> $leftTrust;
            }

            return $this->versions->compare($right['latest_version'], $left['latest_version']);
        });

        return $candidates[0];
    }

    private function state(string $latest, ?InstalledExtension $installed, mixed $requirements, Collection $installedById): string
    {
        if (! $this->previewCompatible($requirements, $installedById)) {
            return 'incompatible';
        }
        if ($installed === null) {
            return 'available';
        }

        $comparison = $this->versions->compare($latest, $installed->installed_version);

        return $comparison > 0 ? 'update' : ($comparison === 0 ? 'up_to_date' : 'newer_installed');
    }

    private function previewCompatible(mixed $requirements, Collection $installedById): bool
    {
        if (! is_array($requirements)) {
            return true;
        }

        $versions = [
            'azuriom' => $this->azuriomVersion(),
            'php' => PHP_VERSION,
        ];
        if (isset($requirements['gaming-hub-core'])) {
            $core = $installedById->get('gaming-hub-core');
            $constraint = $requirements['gaming-hub-core'];
            if ($core === null || ! is_string($constraint)
                || ! $this->versions->satisfies($core->installed_version, $constraint)) {
                return false;
            }
        }
        foreach ($versions as $key => $version) {
            $constraint = $requirements[$key] ?? null;
            if (is_string($constraint) && ! $this->versions->satisfies($version, $constraint)) {
                return false;
            }
        }
        foreach (($requirements['extensions'] ?? []) as $id => $constraint) {
            $dependency = $installedById->get($id);
            if (! is_string($constraint) || $dependency === null
                || ! $this->versions->satisfies($dependency->installed_version, $constraint)) {
                return false;
            }
        }

        return true;
    }

    private function allowPrereleases(ExtensionSource $source, array $metadata): bool
    {
        return $source->allow_prereleases || (bool) ($metadata['allow_prereleases'] ?? false);
    }

    private function azuriomVersion(): string
    {
        if (class_exists(\Azuriom\Azuriom::class) && method_exists(\Azuriom\Azuriom::class, 'version')) {
            return (string) \Azuriom\Azuriom::version();
        }

        return (string) (defined('AZURIOM_VERSION') ? AZURIOM_VERSION : config('app.version', '1.2.0'));
    }
}
