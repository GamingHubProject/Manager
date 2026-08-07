<?php

declare(strict_types=1);

namespace {
    if (! function_exists('mb_strlen')) {
        function mb_strlen(string $value): int
        {
            return strlen($value);
        }
    }

    $root = dirname(__DIR__);
    foreach ([
        'src/Exceptions/ExtensionOperationFailed.php',
        'src/Exceptions/ExtensionAlreadyCurrent.php',
        'src/Exceptions/ChecksumMismatch.php',
        'src/Exceptions/InvalidExtensionManifest.php',
        'src/Data/ExtensionManifest.php',
        'src/Services/ExtensionManifestValidator.php',
        'src/Services/ExtensionPathGuard.php',
        'src/Services/DirectoryHasher.php',
        'src/Services/ExtensionChecksumVerifier.php',
        'src/Services/ExtensionVersionPolicy.php',
        'src/Services/ReleaseVersionValidator.php',
        'src/Services/InstalledExtensionResolver.php',
        'src/Services/BackupManager.php',
    ] as $file) {
        require_once $root.'/'.$file;
    }

    use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
    use Azuriom\Plugin\GamingHubManager\Exceptions\ChecksumMismatch;
    use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
    use Azuriom\Plugin\GamingHubManager\Exceptions\InvalidExtensionManifest;
    use Azuriom\Plugin\GamingHubManager\Services\BackupManager;
    use Azuriom\Plugin\GamingHubManager\Services\DirectoryHasher;
    use Azuriom\Plugin\GamingHubManager\Services\ExtensionChecksumVerifier;
    use Azuriom\Plugin\GamingHubManager\Services\ExtensionManifestValidator;
    use Azuriom\Plugin\GamingHubManager\Services\ExtensionPathGuard;
    use Azuriom\Plugin\GamingHubManager\Services\ExtensionVersionPolicy;
    use Azuriom\Plugin\GamingHubManager\Services\InstalledExtensionResolver;
    use Azuriom\Plugin\GamingHubManager\Services\ReleaseVersionValidator;

    $failures = [];
    $checks = 0;
    $expect = static function (bool $condition, string $message) use (&$failures, &$checks): void {
        $checks++;
        if (! $condition) {
            $failures[] = $message;
        }
    };
    $expectThrows = static function (callable $callback, string $class, string $needle, string $message) use (&$failures, &$checks): void {
        $checks++;
        try {
            $callback();
            $failures[] = $message.' (no exception)';
        } catch (\Throwable $exception) {
            if (! $exception instanceof $class || ! str_contains($exception->getMessage(), $needle)) {
                $failures[] = $message.' (got '.get_class($exception).': '.$exception->getMessage().')';
            }
        }
    };

    $pluginMeta = json_decode((string) file_get_contents($root.'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
    $packageMeta = json_decode((string) file_get_contents($root.'/gaming-hub-extension.json'), true, 512, JSON_THROW_ON_ERROR);
    $expect(($pluginMeta['version'] ?? null) === '0.1.5', 'Manager plugin version must be 0.1.5.');
    $expect(($packageMeta['version'] ?? null) === '0.1.5', 'Manager package manifest version must be 0.1.5.');
    $expect(($packageMeta['repository'] ?? null) === 'https://github.com/GamingHubProject/Manager', 'Manager repository metadata must use the real repository.');

    $validator = new ExtensionManifestValidator();
    $pathGuard = new ExtensionPathGuard();
    $hasher = new DirectoryHasher();
    $checksums = new ExtensionChecksumVerifier();
    $versions = new ExtensionVersionPolicy();
    $releaseVersions = new ReleaseVersionValidator($versions);

    foreach (['gaming-hub-core', 'gaming-hub-panel', 'feature-addon'] as $validId) {
        $expect($pathGuard->validateId($validId) === $validId, 'Canonical package ID was rejected: '.$validId);
    }
    foreach (['Gaming-Hub-Core', 'gaming_hub_core', '../gaming-hub-core', 'gaming-hub-core/child'] as $invalidId) {
        $expectThrows(
            fn () => $pathGuard->validateId($invalidId),
            ExtensionOperationFailed::class,
            'Unsafe extension identifier rejected',
            'Unsafe/alias package ID must be rejected: '.$invalidId,
        );
    }

    $tmp = sys_get_temp_dir().'/gaming-hub-manager-m05-'.bin2hex(random_bytes(6));
    $core = $tmp.'/gaming-hub-core';
    $panel = $tmp.'/gaming-hub-panel';
    mkdir($core, 0755, true);
    mkdir($panel, 0755, true);

    $corePlugin = [
        'id' => 'gaming-hub-core',
        'name' => 'Gaming Hub Core',
        'version' => '0.7.0',
        'description' => 'Core fixture',
        'authors' => ['GamingHubProject'],
        'azuriom_api' => '1.2.0',
    ];
    file_put_contents($core.'/plugin.json', json_encode($corePlugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($core.'/fixture.txt', "core-v1\n");

    $panelPlugin = [
        'id' => 'gaming-hub-panel',
        'name' => 'Gaming Hub Panel',
        'version' => '0.2.0',
        'description' => 'Panel fixture',
        'authors' => ['GamingHubProject'],
        'azuriom_api' => '1.2.0',
    ];
    file_put_contents($panel.'/plugin.json', json_encode($panelPlugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $resolverReflection = new \ReflectionClass(InstalledExtensionResolver::class);
    $resolver = $resolverReflection->newInstanceWithoutConstructor();
    $manifestsProperty = $resolverReflection->getProperty('manifests');
    $manifestsProperty->setAccessible(true);
    $manifestsProperty->setValue($resolver, $validator);

    $manualCore = $resolver->readManifest($core, 'gaming-hub-core');
    $expect($manualCore->id === 'gaming-hub-core', 'Manual Core fixture identity was not read from filesystem.');
    $expect($manualCore->version === '0.7.0', 'Manual Core fixture version was not read from filesystem.');

    $corePlugin['version'] = '0.7.1';
    file_put_contents($core.'/plugin.json', json_encode($corePlugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $changedCore = $resolver->readManifest($core, 'gaming-hub-core');
    $expect($changedCore->version === '0.7.1', 'Filesystem manifest version change was not observed immediately.');
    $expectThrows(
        fn () => $resolver->readManifest($core, 'gaming-hub-panel'),
        ExtensionOperationFailed::class,
        'Package identity mismatch',
        'Filesystem package must not masquerade as a different requested ID.',
    );

    $panelManifest = $validator->validate(null, $panelPlugin);
    $expect($panelManifest->requires['extensions'] === [], 'Plugin-only Panel fixture unexpectedly gained package dependencies.');
    $storedContract = $panelManifest->toArray();
    $storedContract['requires']['gaming-hub-core'] = '>=0.7.0';

    $backupReflection = new \ReflectionClass(BackupManager::class);
    $backupManager = $backupReflection->newInstanceWithoutConstructor();
    $contractMethod = $backupReflection->getMethod('manifestWithStoredContract');
    $contractMethod->setAccessible(true);
    /** @var ExtensionManifest $preserved */
    $preserved = $contractMethod->invoke($backupManager, $panelManifest, $storedContract, false);
    $expect(($preserved->requires['gaming-hub-core'] ?? null) === '>=0.7.0', 'Backup restore lost stored dependency contract for plugin.json-only package.');
    $canonical = $contractMethod->invoke($backupManager, $panelManifest, $storedContract, true);
    $expect(! isset($canonical->requires['gaming-hub-core']), 'Canonical package manifest must not be overridden by stored legacy contract.');

    $snapshotMethod = $backupReflection->getMethod('dependentSnapshotFrom');
    $snapshotMethod->setAccessible(true);
    $snapshot = $snapshotMethod->invoke($backupManager, [
        ['id' => 'gaming-hub-panel', 'enabled' => true, 'depth' => 1],
        ['id' => 'feature-a', 'enabled' => true, 'depth' => 2],
        ['id' => 'disabled-addon', 'enabled' => false, 'depth' => 2],
        ['id' => 'feature-b', 'enabled' => true, 'depth' => 3],
    ]);
    $expect($snapshot['restore_order'] === ['gaming-hub-panel', 'disabled-addon', 'feature-a', 'feature-b'], 'Backup restore dependency enable order is not nearest-first/deterministic.');
    $expect($snapshot['disable_order'] === ['feature-b', 'feature-a', 'disabled-addon', 'gaming-hub-panel'], 'Backup restore dependency disable order is not deepest-first/deterministic.');
    $expect($snapshot['dependents']['disabled-addon']['enabled'] === false, 'Backup restore snapshot lost disabled dependent state.');

    $hash1 = $hasher->hash($core);
    $hash2 = $hasher->hash($core);
    $expect($hash1 === $hash2, 'Directory integrity hash is not deterministic.');
    file_put_contents($core.'/fixture.txt', "core-v2\n");
    $hash3 = $hasher->hash($core);
    $expect($hash1 !== $hash3, 'Directory integrity hash did not detect file change.');

    $checksumFile = $tmp.'/asset.bin';
    file_put_contents($checksumFile, 'gaming-hub-manager-m05');
    $expectedChecksum = hash_file('sha256', $checksumFile);
    $expect($checksums->verify($checksumFile, $expectedChecksum) === $expectedChecksum, 'Exact SHA-256 verification failed.');
    $expectThrows(
        fn () => $checksums->verify($checksumFile, str_repeat('0', 64)),
        ChecksumMismatch::class,
        'SHA-256 checksum mismatch',
        'Checksum mismatch must fail safely.',
    );
    $expectThrows(
        fn () => $checksums->normalize('not-a-checksum'),
        ExtensionOperationFailed::class,
        'malformed',
        'Malformed checksum must be rejected.',
    );

    $expect($versions->normalize('v0.1.5') === '0.1.5', 'Version normalization failed.');
    $expect($versions->compare('0.1.5', '0.1.4') > 0, '0.1.5 must compare newer than 0.1.4.');
    $expect($versions->compare('0.7.0', '0.7.0') === 0, 'Equal version comparison failed.');
    $expect($releaseVersions->releaseVersion(['tag_name' => 'v0.1.5']) === '0.1.5', 'Release tag normalization failed.');
    $expect($releaseVersions->assetVersion('gaming-hub-manager-v0.1.5.zip') === '0.1.5', 'Versioned release asset parsing failed.');

    $managerInspection = $validator->validate($packageMeta, $pluginMeta, null, true);
    $releaseVersions->assertConsistent(
        ['tag_name' => 'v0.1.5'],
        ['name' => 'gaming-hub-manager-v0.1.5.zip'],
        $managerInspection,
        ['selected_version' => '0.1.5'],
    );
    $expect(true, 'Release consistency fixture unexpectedly failed.');
    $expectThrows(
        fn () => $releaseVersions->assertConsistent(
            ['tag_name' => 'v0.1.5'],
            ['name' => 'gaming-hub-manager-v0.1.4.zip'],
            $managerInspection,
            ['selected_version' => '0.1.5'],
        ),
        ExtensionOperationFailed::class,
        'asset filename',
        'Mismatched release asset version must be rejected.',
    );

    $expectThrows(
        fn () => $validator->validate(
            ['schema' => 1, 'id' => 'gaming-hub-panel', 'version' => '0.2.0', 'package' => ['plugin_directory' => 'gaming-hub-panel']],
            $panelPlugin,
            ['id' => 'gaming-hub-core'],
        ),
        InvalidExtensionManifest::class,
        'Package identity mismatch',
        'Registry/downloaded manifest identity mismatch must be rejected.',
    );

    $delete = static function (string $path) use (&$delete): void {
        if (! file_exists($path)) {
            return;
        }
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $delete($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    };
    $delete($tmp);

    if ($failures !== []) {
        fwrite(STDERR, "FAILED: M0.5 filesystem/lifecycle primitives\n- ".implode("\n- ", $failures)."\n");
        exit(1);
    }

    echo "PASS: M0.5 filesystem identity, manual manifest/version observation, backup contract/order, integrity, checksum, and release consistency ({$checks} checks)\n";
}
