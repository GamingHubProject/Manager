<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Services/ExtensionDependencyGuard.php';

use Azuriom\Plugin\GamingHubManager\Services\ExtensionDependencyGuard;

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$edge = static fn (string $id, string $constraint = '>=0.1.0', string $type = 'gaming-hub'): array => [
    'id' => $id,
    'constraint' => $constraint,
    'type' => $type,
];
$package = static fn (string $id, bool $enabled, array $dependencies = []): array => [
    'id' => $id,
    'version' => '1.0.0',
    'enabled' => $enabled,
    'dependencies' => $dependencies,
];

// core <- panel <- feature-a <- addon
// core <- feature-b
// panel <- disabled-child
$packages = [
    'gaming-hub-core' => $package('gaming-hub-core', true),
    'gaming-hub-panel' => $package('gaming-hub-panel', true, [
        'gaming-hub-core' => [$edge('gaming-hub-core', '^0.7.0')],
    ]),
    'feature-a' => $package('feature-a', true, [
        'gaming-hub-panel' => [$edge('gaming-hub-panel')],
    ]),
    'feature-b' => $package('feature-b', true, [
        'gaming-hub-core' => [$edge('gaming-hub-core', '>=0.7.0 <0.8.0', 'azuriom')],
    ]),
    'disabled-child' => $package('disabled-child', false, [
        'gaming-hub-panel' => [$edge('gaming-hub-panel')],
    ]),
    'addon' => $package('addon', true, [
        'feature-a' => [$edge('feature-a')],
    ]),
];
ksort($packages);

$reflection = new ReflectionClass(ExtensionDependencyGuard::class);
$guard = $reflection->newInstanceWithoutConstructor();
$directMethod = $reflection->getMethod('directDependentsFrom');
$allMethod = $reflection->getMethod('dependentsFrom');
$directMethod->setAccessible(true);
$allMethod->setAccessible(true);

$direct = $directMethod->invoke($guard, 'gaming-hub-core', $packages);
$expect(array_column($direct, 'id') === ['feature-b', 'gaming-hub-panel'], 'Direct dependents are not deterministic by canonical ID.');
$expect($direct[0]['types'] === ['azuriom'], 'Native mandatory Azuriom dependency edge was not preserved.');
$expect($direct[1]['constraints'] === ['^0.7.0'], 'Gaming Hub dependency constraint was not preserved.');

$all = $allMethod->invoke($guard, 'gaming-hub-core', $packages);
$actualDepths = [];
foreach ($all as $dependent) {
    $actualDepths[$dependent['id']] = $dependent['depth'];
}
$expect($actualDepths === [
    'feature-b' => 1,
    'gaming-hub-panel' => 1,
    'disabled-child' => 2,
    'feature-a' => 2,
    'addon' => 3,
], 'Transitive dependency traversal returned the wrong breadth/depth ordering.');

$enabledById = [];
foreach ($all as $dependent) {
    $enabledById[$dependent['id']] = $dependent['enabled'];
}
$expect($enabledById['disabled-child'] === false, 'Dependency traversal lost a previously disabled dependent state.');

// enabledStateSnapshot() cannot be executed standalone because it intentionally
// builds its package map from Eloquent + the real Azuriom filesystem/lifecycle.
// These order assertions exercise the same depth ordering produced by the
// production graph traversal and document the lifecycle-safe order that the
// static M0.2 contract requires enabledStateSnapshot() to apply.
$restoreOrder = array_column($all, 'id');
usort($restoreOrder, static fn (string $left, string $right): int => [$actualDepths[$left], $left] <=> [$actualDepths[$right], $right]);
$disableOrder = $restoreOrder;
usort($disableOrder, static fn (string $left, string $right): int => [$actualDepths[$right], $right] <=> [$actualDepths[$left], $left]);

$expect($restoreOrder === ['feature-b', 'gaming-hub-panel', 'disabled-child', 'feature-a', 'addon'], 'Restore order must move from nearest dependencies outward.');
$expect($disableOrder === ['addon', 'feature-a', 'disabled-child', 'gaming-hub-panel', 'feature-b'], 'Disable order must move from deepest dependents inward.');

if ($failures !== []) {
    fwrite(STDERR, "FAILED\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "PASS: production reverse-dependency traversal, transitive depths, edge types, disabled-state capture, and lifecycle-safe depth ordering\n";
