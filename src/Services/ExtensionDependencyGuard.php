<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Models\InstalledExtension;

final class ExtensionDependencyGuard
{
    public function __construct(
        private ExtensionVersionPolicy $versions,
        private ExtensionPathGuard $paths,
        private InstalledExtensionResolver $installed,
        private AzuriomPluginLifecycle $lifecycle,
    ) {
    }

    public function assertCandidateDependencies(ExtensionManifest $candidate): void
    {
        $packages = $this->packageMap();
        foreach ($this->manifestDependencies($candidate->requires) as $dependency) {
            $this->assertDependencySatisfied($candidate->id, $dependency, $packages);
        }
    }

    public function assertUpdateAllowed(ExtensionManifest $candidate): void
    {
        $packages = $this->packageMap();
        foreach ($this->manifestDependencies($candidate->requires) as $dependency) {
            $this->assertDependencySatisfied($candidate->id, $dependency, $packages);
        }

        foreach ($this->directDependentsFrom($candidate->id, $packages) as $dependent) {
            foreach ($dependent['constraints'] as $constraint) {
                if (! $this->versions->satisfies($candidate->version, $constraint)) {
                    throw new ExtensionOperationFailed($this->dependencyFailure(
                        $dependent['id'],
                        $candidate->id,
                        $candidate->version,
                        $constraint,
                        $packages,
                    ));
                }
            }
        }
    }

    public function assertManifestEnableAllowed(ExtensionManifest $candidate): void
    {
        $packages = $this->packageMap();
        foreach ($this->manifestDependencies($candidate->requires) as $dependency) {
            $this->assertDependencySatisfied($candidate->id, $dependency, $packages);
            if (! ($packages[$dependency['id']]['enabled'] ?? false)) {
                throw new ExtensionOperationFailed(
                    'Cannot enable '.$candidate->id.' because mandatory dependency '.$dependency['id'].' is disabled.',
                );
            }
        }

        $this->lifecycle->assertRequirementsSatisfied($candidate->id);
    }

    public function assertEnableAllowed(string $extensionId): void
    {
        $extensionId = $this->paths->validateId($extensionId);
        $packages = $this->packageMap();
        $package = $packages[$extensionId] ?? null;
        if ($package === null) {
            throw new ExtensionOperationFailed('Cannot enable a package that is not installed on the filesystem.');
        }

        foreach ($package['dependencies'] as $dependencies) {
            foreach ($dependencies as $dependency) {
                $this->assertDependencySatisfied($extensionId, $dependency, $packages);
                if (! ($packages[$dependency['id']]['enabled'] ?? false)) {
                    throw new ExtensionOperationFailed(
                        'Cannot enable '.$extensionId.' because mandatory dependency '.$dependency['id'].' is disabled.',
                    );
                }
            }
        }

        // Azuriom remains authoritative for its native enabled-dependency semantics,
        // including the supported optional `dependency?` syntax in plugin.json.
        $this->lifecycle->assertRequirementsSatisfied($extensionId);
    }

    public function assertUninstallAllowed(string $extensionId): void
    {
        $extensionId = $this->paths->validateId($extensionId);
        if ($extensionId === 'gaming-hub-manager') {
            throw new ExtensionOperationFailed('Gaming Hub Manager cannot uninstall itself.');
        }

        $dependents = $this->dependentsOf($extensionId);
        if ($dependents !== []) {
            $labels = array_map(
                static fn (array $dependent): string => $dependent['id'].($dependent['depth'] > 1 ? ' (transitive)' : ''),
                $dependents,
            );
            throw new ExtensionOperationFailed(
                'Uninstall blocked because installed packages depend on it: '.implode(', ', $labels).'.',
            );
        }
    }

    /**
     * @return list<array{id: string, constraint: string, constraints: list<string>, types: list<string>, depth: int, enabled: bool}>
     */
    public function dependentsOf(string $extensionId): array
    {
        $extensionId = $this->paths->validateId($extensionId);

        return $this->dependentsFrom($extensionId, $this->packageMap());
    }

    /**
     * @return list<array{id: string, constraint: string, constraints: list<string>, types: list<string>, depth: int, enabled: bool}>
     */
    public function enabledDependentsOf(string $extensionId): array
    {
        return array_values(array_filter(
            $this->dependentsOf($extensionId),
            static fn (array $dependent): bool => $dependent['enabled'],
        ));
    }

    /**
     * Snapshot used by update/reinstall/disable rollback and restoration.
     *
     * @return array{
     *   target: array{id: string, enabled: bool},
     *   dependents: array<string, array{id: string, enabled: bool, depth: int}>,
     *   disable_order: list<string>,
     *   restore_order: list<string>
     * }
     */
    public function enabledStateSnapshot(string $extensionId): array
    {
        $extensionId = $this->paths->validateId($extensionId);
        $packages = $this->packageMap();
        $target = $packages[$extensionId] ?? null;
        if ($target === null) {
            throw new ExtensionOperationFailed('Installed package files were not found.');
        }

        $dependents = $this->dependentsFrom($extensionId, $packages);
        $snapshot = [];
        foreach ($dependents as $dependent) {
            $snapshot[$dependent['id']] = [
                'id' => $dependent['id'],
                'enabled' => $dependent['enabled'],
                'depth' => $dependent['depth'],
            ];
        }

        $restoreOrder = array_column($dependents, 'id');
        usort($restoreOrder, function (string $left, string $right) use ($snapshot): int {
            return [$snapshot[$left]['depth'], $left] <=> [$snapshot[$right]['depth'], $right];
        });
        $disableOrder = $restoreOrder;
        usort($disableOrder, function (string $left, string $right) use ($snapshot): int {
            return [$snapshot[$right]['depth'], $right] <=> [$snapshot[$left]['depth'], $left];
        });

        return [
            'target' => ['id' => $extensionId, 'enabled' => $target['enabled']],
            'dependents' => $snapshot,
            'disable_order' => $disableOrder,
            'restore_order' => $restoreOrder,
        ];
    }

    /**
     * @return array<string, array{id: string, version: string, enabled: bool, dependencies: array<string, list<array{id: string, constraint: string, type: string}>>}>
     */
    private function packageMap(): array
    {
        // Manager metadata is a cache of lifecycle information, never installation authority.
        $this->installed->reconcileFilesystem();
        $records = InstalledExtension::query()->get()->keyBy('extension_id');
        $packages = [];
        $entries = scandir($this->paths->pluginsRoot()) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $this->paths->pluginsRoot().DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($path) || ! is_file($path.'/plugin.json')) {
                continue;
            }

            try {
                $manifest = $this->installed->readManifest($path, $entry);
                $record = $records->get($entry);
                $requirements = $manifest->requires;
                if (! is_file($path.'/gaming-hub-extension.json')
                    && $record !== null
                    && is_array($record->manifest_snapshot['requires'] ?? null)) {
                    // Preserve Manager's registry contract only when the package has no
                    // canonical Gaming Hub manifest of its own. The installed version and
                    // identity still come exclusively from the filesystem manifests.
                    $requirements = $record->manifest_snapshot['requires'];
                }

                $dependencies = [];
                foreach ($this->manifestDependencies($requirements) as $dependency) {
                    $this->addDependency($dependencies, $dependency['id'], $dependency['constraint'], 'gaming-hub');
                }

                $plugin = json_decode((string) file_get_contents($path.'/plugin.json'), true);
                if (! is_array($plugin)) {
                    continue;
                }
                foreach (($plugin['dependencies'] ?? []) as $dependencyId => $constraint) {
                    if (! is_string($dependencyId) || ! is_string($constraint)) {
                        continue;
                    }
                    $optional = str_ends_with($dependencyId, '?');
                    $dependencyId = rtrim($dependencyId, '?');
                    if ($optional || $dependencyId === 'azuriom' || $dependencyId === '') {
                        continue;
                    }
                    $this->addDependency($dependencies, $dependencyId, $constraint, 'azuriom');
                }

                $packages[$entry] = [
                    'id' => $entry,
                    'version' => $manifest->version,
                    'enabled' => $this->lifecycle->isEnabled($entry),
                    'dependencies' => $dependencies,
                ];
            } catch (\Throwable) {
                // Invalid/mismatched directories are not installed packages and therefore
                // cannot satisfy or widen a dependency relationship.
            }
        }

        ksort($packages);

        return $packages;
    }

    /**
     * @return list<array{id: string, constraint: string, type: string}>
     */
    private function manifestDependencies(array $requires): array
    {
        $dependencies = [];
        if (isset($requires['gaming-hub-core'])) {
            if (! is_string($requires['gaming-hub-core']) || trim($requires['gaming-hub-core']) === '') {
                throw new ExtensionOperationFailed('The package declares an invalid Gaming Hub Core dependency.');
            }
            $dependencies[] = [
                'id' => 'gaming-hub-core',
                'constraint' => trim($requires['gaming-hub-core']),
                'type' => 'gaming-hub',
            ];
        }

        foreach (($requires['extensions'] ?? []) as $dependencyId => $constraint) {
            if (! is_string($dependencyId) || ! is_string($constraint) || trim($constraint) === '') {
                throw new ExtensionOperationFailed('The package declares an invalid dependency.');
            }
            $dependencies[] = [
                'id' => $this->paths->validateId($dependencyId),
                'constraint' => trim($constraint),
                'type' => 'gaming-hub',
            ];
        }

        return $dependencies;
    }

    /**
     * @param array<string, list<array{id: string, constraint: string, type: string}>> $dependencies
     */
    private function addDependency(array &$dependencies, string $dependencyId, string $constraint, string $type): void
    {
        $dependencyId = $this->paths->validateId($dependencyId);
        $constraint = trim($constraint);
        if ($constraint === '') {
            throw new ExtensionOperationFailed('The package declares an invalid dependency constraint.');
        }

        $dependencies[$dependencyId] ??= [];
        foreach ($dependencies[$dependencyId] as $dependency) {
            if ($dependency['constraint'] === $constraint && $dependency['type'] === $type) {
                return;
            }
        }
        $dependencies[$dependencyId][] = [
            'id' => $dependencyId,
            'constraint' => $constraint,
            'type' => $type,
        ];
    }

    /**
     * @param array{id: string, constraint: string, type: string} $dependency
     * @param array<string, array<string, mixed>> $packages
     */
    private function assertDependencySatisfied(string $candidateId, array $dependency, array $packages): void
    {
        $installed = $packages[$dependency['id']] ?? null;
        if ($installed === null || ! $this->versions->satisfies($installed['version'], $dependency['constraint'])) {
            throw new ExtensionOperationFailed($this->dependencyFailure(
                $candidateId,
                $dependency['id'],
                $installed['version'] ?? null,
                $dependency['constraint'],
                $packages,
            ));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $packages
     * @return list<array{id: string, constraint: string, constraints: list<string>, types: list<string>, depth: int, enabled: bool}>
     */
    private function directDependentsFrom(string $extensionId, array $packages): array
    {
        $dependents = [];
        foreach ($packages as $candidateId => $candidate) {
            if ($candidateId === $extensionId || ! isset($candidate['dependencies'][$extensionId])) {
                continue;
            }
            $edges = $candidate['dependencies'][$extensionId];
            $constraints = array_values(array_unique(array_column($edges, 'constraint')));
            $types = array_values(array_unique(array_column($edges, 'type')));
            $dependents[] = [
                'id' => $candidateId,
                'constraint' => implode(' && ', $constraints),
                'constraints' => $constraints,
                'types' => $types,
                'depth' => 1,
                'enabled' => (bool) $candidate['enabled'],
            ];
        }

        usort($dependents, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $dependents;
    }

    /**
     * @param array<string, array<string, mixed>> $packages
     * @return list<array{id: string, constraint: string, constraints: list<string>, types: list<string>, depth: int, enabled: bool}>
     */
    private function dependentsFrom(string $extensionId, array $packages): array
    {
        $results = [];
        $queue = [[$extensionId, 0]];
        $seen = [$extensionId => true];

        while ($queue !== []) {
            [$dependencyId, $depth] = array_shift($queue);
            foreach ($this->directDependentsFrom($dependencyId, $packages) as $dependent) {
                if (isset($seen[$dependent['id']])) {
                    continue;
                }
                $seen[$dependent['id']] = true;
                $dependent['depth'] = $depth + 1;
                $results[] = $dependent;
                $queue[] = [$dependent['id'], $depth + 1];
            }
        }

        usort($results, static fn (array $left, array $right): int => [$left['depth'], $left['id']] <=> [$right['depth'], $right['id']]);

        return $results;
    }

    /**
     * @param array<string, array<string, mixed>> $packages
     */
    private function dependencyFailure(
        string $candidateId,
        string $dependencyId,
        ?string $installedVersion,
        string $constraint,
        array $packages,
    ): string {
        $installed = [];
        foreach ($packages as $id => $package) {
            $installed[$id] = $package['version'];
        }

        return 'Dependency validation failed: package='.$candidateId
            .'; requested='.$dependencyId
            .'; installed_packages='.json_encode($installed, JSON_UNESCAPED_SLASHES)
            .'; installed_version='.($installedVersion ?? 'missing')
            .'; constraint='.$constraint
            .'; comparison=not_satisfied.';
    }
}
