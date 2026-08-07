<?php

namespace Azuriom\Plugin\GamingHubManager\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Illuminate\Database\Eloquent\Model;

final class ManagerSetting extends Model
{
    use HasTablePrefix;

    protected $prefix = 'gaminghub_manager_';
    protected $table = 'gaminghub_manager_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];
}
