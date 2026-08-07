<?php

require __DIR__.'/../src/Exceptions/ExtensionOperationFailed.php';
require __DIR__.'/../src/Exceptions/ExtensionAlreadyCurrent.php';
require __DIR__.'/../src/Services/ExtensionVersionPolicy.php';

use Azuriom\Plugin\GamingHubManager\Services\ExtensionVersionPolicy;

$policy = new ExtensionVersionPolicy();

$cases = [
    ['gaming-hub-core', '0.7.0', '^0.6.0', true],
    ['gaming-hub-core', '0.6.0', '^0.6.0', true],
    ['gaming-hub-core', '0.5.9', '^0.6.0', false],
    ['gaming-hub-core', '1.0.0', '^0.6.0', false],
    ['other-extension', '0.7.0', '^0.6.0', false],
    ['other-extension', '1.2.0', '^1.1.0', true],
];

foreach ($cases as [$dependencyId, $version, $constraint, $expected]) {
    $actual = $policy->satisfiesPackageDependency($dependencyId, $version, $constraint);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "FAIL: %s %s %s expected %s, got %s\n",
            $dependencyId,
            $version,
            $constraint,
            $expected ? 'true' : 'false',
            $actual ? 'true' : 'false',
        ));
        exit(1);
    }
}

if ($policy->satisfies('0.7.0', '^0.6.0') !== false) {
    fwrite(STDERR, "FAIL: base Composer-compatible comparator was changed.\n");
    exit(1);
}

echo "PASS: Core dependency compatibility and unchanged base SemVer behavior\n";
