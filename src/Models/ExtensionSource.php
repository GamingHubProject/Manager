<?php

namespace Azuriom\Plugin\GamingHubManager\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Illuminate\Database\Eloquent\Model;

final class ExtensionSource extends Model
{
    use HasTablePrefix;

    protected $prefix = 'gaminghub_manager_';
    protected $table = 'gaminghub_manager_sources';
    protected $fillable = [
        'source_id', 'type', 'name', 'url', 'trust_level', 'trusted', 'enabled',
        'allow_prereleases', 'allow_private_host', 'added_by',
        'last_successful_refresh_at', 'last_error', 'metadata',
    ];
    protected $casts = [
        'trusted' => 'boolean',
        'enabled' => 'boolean',
        'allow_prereleases' => 'boolean',
        'allow_private_host' => 'boolean',
        'last_successful_refresh_at' => 'datetime',
        'metadata' => 'array',
    ];
}
