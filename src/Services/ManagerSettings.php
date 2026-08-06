<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Models\ManagerSetting;
use Illuminate\Support\Facades\Schema;

final class ManagerSettings
{
    private const DEFINITIONS = [
        'allow_private_hosts' => ['type' => 'bool', 'config' => 'allow_private_hosts'],
        'retain_successful_update_backups' => ['type' => 'bool', 'config' => 'retain_successful_update_backups'],
        'auto_import_legacy_core_metadata' => ['type' => 'bool', 'config' => 'auto_import_legacy_core_metadata'],
        'stale_staging_hours' => ['type' => 'int', 'config' => 'stale_staging_hours', 'min' => 1, 'max' => 168],
        'operation_log_retention_days' => ['type' => 'int', 'config' => 'operation_log_retention_days', 'min' => 7, 'max' => 3650],
    ];

    public function all(): array
    {
        $values = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function get(string $key): bool|int|string|null
    {
        $definition = self::DEFINITIONS[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        $default = config('gaming-hub-manager.manager.'.$definition['config']);
        if (! $this->tableExists()) {
            return $default;
        }

        $stored = ManagerSetting::query()->find($key)?->value;
        if ($stored === null) {
            return $default;
        }

        return $definition['type'] === 'bool'
            ? filter_var($stored, FILTER_VALIDATE_BOOLEAN)
            : (int) $stored;
    }

    public function update(array $values): void
    {
        foreach (self::DEFINITIONS as $key => $definition) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $value = $definition['type'] === 'bool' ? (bool) $values[$key] : (int) $values[$key];
            if ($definition['type'] === 'int') {
                $value = max((int) $definition['min'], min((int) $definition['max'], $value));
            }
            ManagerSetting::query()->updateOrCreate(['key' => $key], ['value' => $value ? (string) $value : '0']);
        }
        $this->applyToConfig();
    }

    public function applyToConfig(): void
    {
        foreach (self::DEFINITIONS as $key => $definition) {
            config()->set('gaming-hub-manager.manager.'.$definition['config'], $this->get($key));
        }
    }


    public function getInternal(string $key): ?string
    {
        if (! $this->tableExists()) {
            return null;
        }

        return ManagerSetting::query()->find($key)?->value;
    }

    public function putInternal(string $key, mixed $value): void
    {
        if ($this->tableExists()) {
            ManagerSetting::query()->updateOrCreate(['key' => $key], ['value' => is_scalar($value) ? (string) $value : json_encode($value)]);
        }
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('gaminghub_manager_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
