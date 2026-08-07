<?php

declare(strict_types=1);

namespace {
    if (! function_exists('mb_strlen')) {
        function mb_strlen(string $value): int
        {
            return strlen($value);
        }
    }

    require_once __DIR__.'/../src/Data/ExtensionManifest.php';
    require_once __DIR__.'/../src/Exceptions/InvalidExtensionManifest.php';
    require_once __DIR__.'/../src/Services/ExtensionManifestValidator.php';

    use Azuriom\Plugin\GamingHubManager\Exceptions\InvalidExtensionManifest;
    use Azuriom\Plugin\GamingHubManager\Services\ExtensionManifestValidator;

    $failures = [];
    $expect = static function (bool $condition, string $message) use (&$failures): void {
        if (! $condition) {
            $failures[] = $message;
        }
    };
    $expectRejected = static function (callable $callback, string $needle, string $message) use (&$failures): void {
        try {
            $callback();
            $failures[] = $message;
        } catch (InvalidExtensionManifest $exception) {
            if (! str_contains($exception->getMessage(), $needle)) {
                $failures[] = $message.' Unexpected error: '.$exception->getMessage();
            }
        }
    };

    $validator = new ExtensionManifestValidator();
    $managerPlugin = json_decode((string) file_get_contents(__DIR__.'/../plugin.json'), true, 512, JSON_THROW_ON_ERROR);
    $managerManifest = json_decode((string) file_get_contents(__DIR__.'/../gaming-hub-extension.json'), true, 512, JSON_THROW_ON_ERROR);

    $blocked = false;
    try {
        $validator->validate($managerManifest, $managerPlugin);
    } catch (InvalidExtensionManifest $exception) {
        $blocked = str_contains($exception->getMessage(), 'cannot manage or replace itself');
    }
    $expect($blocked, 'Manager archive self-management must remain blocked.');

    $inspection = $validator->validate($managerManifest, $managerPlugin, null, true);
    $expect($inspection->id === 'gaming-hub-manager', 'Installed Manager inspection should be allowed.');
    $expect($inspection->pluginDirectory === 'gaming-hub-manager', 'Installed Manager directory contract is invalid.');

    foreach ([
        ['id' => 'gaming-hub-core', 'name' => 'Gaming Hub Core', 'version' => '0.6.6'],
        ['id' => 'gaming-hub-panel', 'name' => 'Gaming Hub Panel', 'version' => '0.1.0'],
    ] as $legacyPlugin) {
        $legacyPlugin += [
            'description' => 'Legacy plugin.json-only package.',
            'authors' => ['Gaming Hub'],
            'azuriom_api' => '1.2.0',
        ];
        $normalized = $validator->validate(null, $legacyPlugin);
        $expect($normalized->id === $legacyPlugin['id'], $legacyPlugin['id'].' plugin.json-only package was rejected.');
        $expect($normalized->pluginDirectory === $legacyPlugin['id'], $legacyPlugin['id'].' directory was not inferred safely.');
    }

    $basePlugin = [
        'id' => 'gaming-hub-panel',
        'name' => 'Gaming Hub Panel',
        'version' => '0.2.0',
        'description' => 'Panel integration.',
        'authors' => ['Gaming Hub'],
        'azuriom_api' => '1.2.0',
    ];
    $baseManifest = [
        'schema' => 1,
        'id' => 'gaming-hub-panel',
        'version' => '0.2.0',
        'type' => 'integration',
        'package' => ['plugin_directory' => 'gaming-hub-panel', 'checksum_algorithm' => 'sha256'],
    ];

    $expectRejected(
        fn () => $validator->validate($baseManifest, $basePlugin, ['id' => 'gaming-hub-core']),
        'Package identity mismatch',
        'Registry ID mismatch must be rejected.',
    );
    $mismatchedManifest = $baseManifest;
    $mismatchedManifest['id'] = 'gaming-hub-core';
    $mismatchedManifest['package']['plugin_directory'] = 'gaming-hub-core';
    $expectRejected(
        fn () => $validator->validate($mismatchedManifest, $basePlugin),
        'Package identity mismatch',
        'plugin.json and Gaming Hub manifest ID mismatch must be rejected.',
    );
    foreach (['Gaming-Hub-Panel', 'gaming_hub_panel'] as $invalidId) {
        $invalid = $basePlugin;
        $invalid['id'] = $invalidId;
        $expectRejected(
            fn () => $validator->validate(null, $invalid),
            'Invalid package identifier',
            'Alias/case/underscore package ID must be rejected: '.$invalidId,
        );
    }

    $directoryMismatch = $baseManifest;
    $directoryMismatch['package']['plugin_directory'] = 'gaming-hub-core';
    $expectRejected(
        fn () => $validator->validate($directoryMismatch, $basePlugin),
        'plugin_directory must match the canonical package ID',
        'Package directory alias/mismatch must be rejected.',
    );
    $expectRejected(
        fn () => $validator->validate($baseManifest, $basePlugin, ['id' => 'Gaming-Hub-Panel']),
        'Invalid package identifier',
        'Registry package ID case alias must be rejected.',
    );

    if ($failures !== []) {
        fwrite(STDERR, "FAILED\n- ".implode("\n- ", $failures)."\n");
        exit(1);
    }

    echo "PASS: installed self-detection, legacy plugin inspection, and strict canonical package identity\n";
}
