<?php

namespace Azuriom\Plugin\GamingHubManager\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Illuminate\Database\Eloquent\Model;

final class PackageBackup extends Model
{
    use HasTablePrefix;

    protected $prefix = 'gaminghub_manager_';
    protected $table = 'gaminghub_manager_backups';
    protected $fillable = [
        'backup_uuid', 'extension_id', 'version', 'relative_path', 'integrity_hash',
        'enabled_snapshot', 'manifest_snapshot', 'reason', 'source_operation_uuid',
        'created_by', 'restored_at', 'restored_by',
    ];
    protected $casts = [
        'enabled_snapshot' => 'boolean',
        'manifest_snapshot' => 'array',
        'restored_at' => 'datetime',
    ];
}
