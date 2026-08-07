<?php

declare(strict_types=1);

use Azuriom\Plugin\GamingHubManager\Services\LegacyRegistryPolicy;

require_once __DIR__.'/../src/Services/ExtensionSourceManager.php';
require_once __DIR__.'/../src/Services/LegacyRegistryPolicy.php';

$policy = new LegacyRegistryPolicy();
$failures = [];

$check = static function (bool $condition, string $label) use (&$failures): void {
    if (! $condition) {
        $failures[] = $label;
    }
};

$current = 'https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json';
$oldRegistry = 'https://raw.githubusercontent.com/RosesOfDorns/gaming-hub-registry/main/registry.json';
$oldCommunity = 'https://raw.githubusercontent.com/RosesOfDorns/gaming-hub-community/main/registry.json';

$check($policy->isCanonicalOfficialUrl($current), 'canonical official URL is not recognized');
$check(
    $policy->isCanonicalOfficialUrl('HTTPS://RAW.GITHUBUSERCONTENT.COM/GamingHubProject/Registry/main/registry.json/?cache=1#fragment'),
    'canonical URL normalization is not deterministic',
);
$check(! $policy->isCanonicalOfficialUrl('https://github.com/GamingHubProject/Registry'), 'repository page is incorrectly treated as the raw registry');

$legacyManagerOfficial = [
    'source_id' => 'rosesofdorns-official',
    'type' => 'official',
    'name' => 'RosesOfDorns official registry',
    'url' => $oldRegistry,
];
$check($policy->suppressLegacyImport($legacyManagerOfficial), 'historical Manager official source is not suppressed during legacy import');
$check($policy->isObsoleteManagedStoredSource($legacyManagerOfficial), 'historical Manager official source is not reconcilable');

$legacyCoreArtifact = [
    'source_id' => 'official-gaming-hub',
    'type' => 'registry',
    'name' => 'Official Gaming Hub Registry (imported from Core)',
    'url' => $oldCommunity,
];
$check($policy->suppressLegacyImport($legacyCoreArtifact), 'historical Core-import official artifact is not suppressed');
$check($policy->isObsoleteManagedStoredSource($legacyCoreArtifact), 'historical Core-import official artifact is not reconcilable');

$modernCoreArtifact = [
    'source_id' => 'core-current-official',
    'type' => 'registry',
    'name' => 'GamingHubProject Official Registry (imported from Core)',
    'url' => $current,
];
$check($policy->suppressLegacyImport($modernCoreArtifact), 'current official source imported from Core would duplicate Manager ownership');
$check($policy->isObsoleteManagedStoredSource($modernCoreArtifact), 'stored current official Core-import artifact is not reconcilable');

$legacyCustomSameOldUrl = [
    'source_id' => 'custom-admin-source',
    'type' => 'registry',
    'name' => 'Administrator archive mirror',
    'url' => $oldRegistry,
];
$check(! $policy->suppressLegacyImport($legacyCustomSameOldUrl), 'custom legacy source is deleted based only on old URL');
$check(! $policy->isObsoleteManagedStoredSource($legacyCustomSameOldUrl), 'stored custom source is deleted based only on old URL');

$customCanonicalUrl = [
    'source_id' => 'custom-current-copy',
    'type' => 'registry',
    'name' => 'Administrator explicit registry',
    'url' => $current,
];
$check(! $policy->suppressLegacyImport($customCanonicalUrl), 'legacy custom source is suppressed based only on canonical URL');
$check(! $policy->isObsoleteManagedStoredSource($customCanonicalUrl), 'stored custom source is deleted based only on canonical URL');

$nameOnly = [
    'source_id' => 'custom-name-only',
    'type' => 'registry',
    'name' => 'Official Gaming Hub Registry (imported from Core)',
    'url' => 'https://example.com/registry.json',
];
$check(! $policy->suppressLegacyImport($nameOnly), 'custom source is suppressed based only on imported display-name marker');
$check(! $policy->isObsoleteManagedStoredSource($nameOnly), 'stored custom source is deleted based only on imported display-name marker');

$arbitraryGithub = [
    'source_id' => 'custom-community',
    'type' => 'registry',
    'name' => 'Community Registry',
    'url' => 'https://raw.githubusercontent.com/example/community/main/registry.json',
];
$check(! $policy->suppressLegacyImport($arbitraryGithub), 'arbitrary GitHub registry is incorrectly treated as legacy official data');
$check(! $policy->isObsoleteManagedStoredSource($arbitraryGithub), 'arbitrary GitHub registry is incorrectly treated as Manager-owned');

if ($failures !== []) {
    fwrite(STDERR, "FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- '.$failure."\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: M0.3 canonical registry normalization, legacy official suppression, and custom-source protection\n");
