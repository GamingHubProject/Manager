<?php

$externalAutoload = getenv('GAMING_HUB_TEST_AUTOLOAD');
$autoloadCandidates = array_values(array_filter([
    is_string($externalAutoload) && $externalAutoload !== '' ? $externalAutoload : null,
    __DIR__.'/../vendor/autoload.php',
    dirname(__DIR__, 3).'/vendor/autoload.php',
]));
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

if (! class_exists(Composer\Semver\Semver::class)) {
    echo "SKIP: composer/semver is not available. Run inside Azuriom or set GAMING_HUB_TEST_AUTOLOAD to a Composer autoloader.\n";
    exit(0);
}

require_once __DIR__.'/../src/Exceptions/ExtensionOperationFailed.php';
require_once __DIR__.'/../src/Exceptions/ExtensionAlreadyCurrent.php';
require_once __DIR__.'/../src/Services/ExtensionVersionPolicy.php';

use Azuriom\Plugin\GamingHubManager\Services\ExtensionVersionPolicy;

$policy = new ExtensionVersionPolicy();
$cases = [
    ['0.6.0', '>=0.6.0', true],
    ['0.7.0', '>=0.6.0 <0.8.0', true],
    ['0.6.9', '^0.6.0', true],
    ['0.7.0', '^0.6.0', false],
    ['0.6.9', '~0.6.0', true],
    ['0.7.0', '~0.6.0', false],
    ['0.6.1', '0.6.1', true],
    ['0.6.2', '0.6.1', false],
    ['0.6.0-beta.1', '>=0.6.0', false],
    ['0.6.0-beta.1', '0.6.0-beta.1', true],
    ['0.8.2', '^0.6.0 || ^0.8.0', true],
    ['0.7.0', '^0.6.0 || ^0.8.0', false],
];

foreach ($cases as [$version, $constraint, $expected]) {
    $actual = $policy->satisfies($version, $constraint);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "FAIL: %s %s expected %s, got %s\n",
            $version,
            $constraint,
            $expected ? 'true' : 'false',
            $actual ? 'true' : 'false',
        ));
        exit(1);
    }
}

// There must be no package-specific dependency comparator left to widen Core.
if (method_exists($policy, 'satisfiesPackageDependency')) {
    fwrite(STDERR, "FAIL: package-specific dependency SemVer policy still exists.\n");
    exit(1);
}

echo "PASS: Composer SemVer dependency semantics, prereleases, OR expressions, and no Core widening\n";
