<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    'src/Exceptions/ExtensionOperationFailed.php',
    'src/Exceptions/ExtensionAlreadyCurrent.php',
    'src/Exceptions/ChecksumMismatch.php',
    'src/Data/ExtensionManifest.php',
    'src/Services/ExtensionVersionPolicy.php',
    'src/Services/ReleaseVersionValidator.php',
    'src/Services/GitHubAssetDigestValidator.php',
    'src/Services/RegistryChecksumResolver.php',
    'src/Services/ExtensionChecksumVerifier.php',
    'src/Services/GitHubReleaseClient.php',
] as $file) {
    require_once $root.'/'.$file;
}

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\ChecksumMismatch;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionChecksumVerifier;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionVersionPolicy;
use Azuriom\Plugin\GamingHubManager\Services\GitHubAssetDigestValidator;
use Azuriom\Plugin\GamingHubManager\Services\GitHubReleaseClient;
use Azuriom\Plugin\GamingHubManager\Services\RegistryChecksumResolver;
use Azuriom\Plugin\GamingHubManager\Services\ReleaseVersionValidator;

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function expectException(callable $callback, string $class, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        expect($exception instanceof $class, $message.' (received '.get_class($exception).')');

        return;
    }

    throw new RuntimeException($message.' (no exception)');
}

$versions = new ExtensionVersionPolicy();
$releaseVersions = new ReleaseVersionValidator($versions);
$digests = new GitHubAssetDigestValidator();
$registryChecksums = new RegistryChecksumResolver($versions);
$checksums = new ExtensionChecksumVerifier();

$contents = 'exact downloaded package bytes';
$expected = hash('sha256', $contents);
$asset = [
    'id' => 700,
    'name' => 'gaming-hub-core-v0.7.0.zip',
    'browser_download_url' => 'https://github.com/example/releases/download/v0.7.0/gaming-hub-core-v0.7.0.zip',
    'state' => 'uploaded',
    'digest' => 'sha256:'.$expected,
];
expect($digests->resolve($asset) === $expected, 'GitHub SHA-256 asset digest was not accepted.');
$corePublishedDigest = '1bcb7ce961dff1b66d1eefd41e7b656c80f31c46b1b3b8dfd296be9b7051e2bc';
expect($digests->resolve([
    'id' => 7070,
    'name' => 'gaming-hub-core-v0.7.0.zip',
    'digest' => 'sha256:'.$corePublishedDigest,
]) === $corePublishedDigest, 'The v0.7.0 GitHub asset digest format was not accepted.');

$temp = tempnam(sys_get_temp_dir(), 'ghm-checksum-');
file_put_contents($temp, $contents);
try {
    expect($checksums->verify($temp, $digests->resolve($asset)) === $expected, 'Downloaded asset was not verified against its GitHub digest.');
    file_put_contents($temp, $contents.' changed');
    expectException(
        fn () => $checksums->verify($temp, $digests->resolve($asset)),
        ChecksumMismatch::class,
        'Changed downloaded asset was not rejected.',
    );
} finally {
    @unlink($temp);
}

expect(
    $checksums->parse($expected."  gaming-hub-core-v0.7.0.zip\n", $asset['name']) === $expected,
    'Explicit checksum manifest entry was not accepted.',
);
expect(
    $checksums->parse($expected."\n", $asset['name'], true) === $expected,
    'Exact ZIP sidecar checksum was not accepted.',
);
expect(
    $checksums->parse($expected."  another-package.zip\n", $asset['name']) === null,
    'Checksum belonging to another asset was accepted.',
);

$unsupported = $asset;
$unsupported['digest'] = 'sha512:'.str_repeat('a', 128);
expectException(fn () => $digests->resolve($unsupported), ExtensionOperationFailed::class, 'SHA-512 digest was not rejected.');
$malformed = $asset;
$malformed['digest'] = 'sha256:not-hex';
expectException(fn () => $digests->resolve($malformed), ExtensionOperationFailed::class, 'Malformed GitHub digest was not rejected.');
$unbound = $asset;
unset($unbound['id']);
expectException(fn () => $digests->resolve($unbound), ExtensionOperationFailed::class, 'Unbound GitHub digest was not rejected.');

$selectedWithoutDigest = $asset;
unset($selectedWithoutDigest['digest']);
$otherAsset = [
    'id' => 701,
    'name' => 'gaming-hub-panel-v0.7.0.zip',
    'digest' => 'sha256:'.str_repeat('b', 64),
];
expect($digests->resolve($selectedWithoutDigest) === null, 'Digest from another release asset leaked into the selected asset.');
expect($digests->resolve($otherAsset) === str_repeat('b', 64), 'Other asset fixture digest is invalid.');

expect(
    $registryChecksums->resolve([
        'checksums' => [[
            'version' => '0.7.0',
            'asset' => $asset['name'],
            'sha256' => $expected,
        ]],
    ], '0.7.0', $asset['name']) === $expected,
    'Exact registry-pinned checksum record was not accepted.',
);
expect(
    $registryChecksums->resolve([
        'sha256' => $expected,
        'latest_version' => '0.6.6',
        'release_asset' => $asset['name'],
    ], '0.7.0', $asset['name']) === null,
    'Stale registry-pinned version checksum was accepted.',
);
expect(
    $registryChecksums->resolve([
        'sha256' => $expected,
        'latest_version' => '0.7.0',
        'release_asset' => 'gaming-hub-core-v*.zip',
    ], '0.7.0', $asset['name']) === null,
    'Wildcard registry asset was treated as an exact checksum binding.',
);

$clientReflection = new ReflectionClass(GitHubReleaseClient::class);
$client = $clientReflection->newInstanceWithoutConstructor();
$property = $clientReflection->getProperty('releaseVersions');
$property->setAccessible(true);
$property->setValue($client, $releaseVersions);

$checksumRelease = ['assets' => [
    $asset,
    ['id' => 702, 'name' => $asset['name'].'.sha256', 'browser_download_url' => 'https://github.com/example/sidecar'],
    ['id' => 703, 'name' => 'SHA256SUMS', 'browser_download_url' => 'https://github.com/example/sums'],
]];
$sidecarSelection = $client->selectChecksumAsset($checksumRelease, $asset, null);
expect($sidecarSelection['asset']['name'] === $asset['name'].'.sha256' && $sidecarSelection['allow_bare_hash'] === true, 'Exact ZIP .sha256 sidecar was not prioritized.');
$preferredSelection = $client->selectChecksumAsset($checksumRelease, $asset, 'SHA256SUMS');
expect($preferredSelection['asset']['name'] === 'SHA256SUMS', 'Configured explicit checksum asset was not prioritized.');

$coreAsset = static fn (string $version, ?string $name = null): array => [
    'id' => random_int(1000, 9999),
    'name' => $name ?? 'gaming-hub-core-v'.$version.'.zip',
    'browser_download_url' => 'https://github.com/example/releases/download/v'.$version.'/package.zip',
    'state' => 'uploaded',
];
$release = static fn (string $version, array $assets, bool $draft = false, bool $prerelease = false): array => [
    'id' => random_int(10000, 99999),
    'tag_name' => 'v'.$version,
    'draft' => $draft,
    'prerelease' => $prerelease,
    'published_at' => '2026-08-06T08:00:00Z',
    'assets' => $assets,
    'zipball_url' => 'https://api.github.com/source-code.zip',
];

$releases = [
    $release('9.0.0', [$coreAsset('9.0.0')], true),
    $release('0.8.0-beta.1', [$coreAsset('0.8.0-beta.1')], false, true),
    $release('0.8.0', [], false, false),
    $release('0.7.1', [$coreAsset('0.7.1', 'unrelated-v0.7.1.zip')]),
    $release('0.6.6', [$coreAsset('0.6.6')]),
    $release('0.7.0', [$coreAsset('0.7.0')]),
];
$selected = $client->selectFromReleases($releases, 'gaming-hub-core-v*.zip', false);
expect($selected['version'] === '0.7.0', 'Highest stable matching semantic release was not selected.');
expect(version_compare($selected['version'], '0.6.6', '>'), 'Stale registry latest_version 0.6.6 hid GitHub v0.7.0.');
expect($selected['asset']['name'] === 'gaming-hub-core-v0.7.0.zip', 'Selected release asset pattern was not enforced.');
$selectedPrerelease = $client->selectFromReleases($releases, 'gaming-hub-core-v*.zip', true);
expect($selectedPrerelease['version'] === '0.8.0-beta.1', 'Prerelease channel did not select the highest matching prerelease.');

foreach ([
    'gaming-hub-core' => 'gaming-hub-core-v*.zip',
    'gaming-hub-panel' => 'gaming-hub-panel-v*.zip',
    'future-package' => 'future-package-v*.zip',
] as $packageId => $pattern) {
    $genericRelease = $release('1.2.3', [[
        'id' => random_int(1000, 9999),
        'name' => $packageId.'-v1.2.3.zip',
        'browser_download_url' => 'https://github.com/example/releases/download/v1.2.3/'.$packageId.'-v1.2.3.zip',
        'state' => 'uploaded',
    ]]);
    expect($client->selectFromReleases([$genericRelease], $pattern)['version'] === '1.2.3', 'Generic package discovery failed for '.$packageId.'.');
}

expectException(
    fn () => $client->selectFromReleases([$release('1.0.0', [])], 'gaming-hub-core-v*.zip'),
    ExtensionOperationFailed::class,
    'GitHub source-code archive without a release asset was not ignored.',
);
expectException(
    fn () => $client->selectFromReleases([$release('1.0.0', [$coreAsset('1.0.0', 'wrong-package-v1.0.0.zip')])], 'gaming-hub-core-v*.zip'),
    ExtensionOperationFailed::class,
    'Non-matching release asset pattern was accepted.',
);

$manifest = new ExtensionManifest(
    1,
    'gaming-hub-core',
    'Gaming Hub Core',
    '0.7.0',
    'core',
    '',
    'Gaming Hub',
    null,
    null,
    [],
    [],
    [],
    'gaming-hub-core',
    'sha256',
    null,
    [],
);
$releaseVersions->assertConsistent(
    ['tag_name' => 'v0.7.0'],
    ['name' => 'gaming-hub-core-v0.7.0.zip'],
    $manifest,
    ['selected_version' => '0.7.0'],
);
expectException(
    fn () => $releaseVersions->assertConsistent(
        ['tag_name' => 'v0.7.0'],
        ['name' => 'gaming-hub-core-v0.6.6.zip'],
        $manifest,
        ['selected_version' => '0.7.0'],
    ),
    ExtensionOperationFailed::class,
    'Versioned asset filename mismatch was not rejected.',
);

print("PASS: GitHub release discovery, checksum source security, and version consistency\n");
