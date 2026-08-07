<?php

namespace Azuriom\Plugin\GamingHubManager\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Illuminate\Database\Eloquent\Model;

final class InstalledExtension extends Model
{
    use HasTablePrefix;

    protected $prefix = 'gaminghub_manager_';
    protected $table = 'gaminghub_manager_packages';
    protected $fillable = [
        'extension_id', 'installed_version', 'source_type', 'source_id', 'source_url',
        'repository_url', 'release_url', 'release_id', 'asset_name', 'checksum',
        'checksum_verified', 'integrity_hash', 'integrity_status', 'integrity_checked_at',
        'trust_level', 'installed_by', 'installed_at', 'enabled_snapshot',
        'manifest_snapshot', 'last_operation_result',
    ];
    protected $casts = [
        'checksum_verified' => 'boolean',
        'integrity_checked_at' => 'datetime',
        'installed_at' => 'datetime',
        'enabled_snapshot' => 'boolean',
        'manifest_snapshot' => 'array',
    ];
}
