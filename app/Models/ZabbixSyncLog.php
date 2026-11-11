<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per Zabbix sync run (#740).
 *
 * @property int $id
 * @property bool $success
 * @property string|null $error
 * @property Carbon $ran_at
 */
class ZabbixSyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['success', 'error', 'ran_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'success' => 'boolean',
        'ran_at' => 'datetime',
    ];
}
