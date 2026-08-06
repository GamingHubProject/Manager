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

    if ($failures !== []) {
        fwrite(STDERR, "FAILED\n- ".implode("\n- ", $failures)."\n");
        exit(1);
    }

    echo "PASS: installed self-detection and legacy plugin.json package inspection\n";
}
