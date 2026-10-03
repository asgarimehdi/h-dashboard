<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per Zabbix sync run (#740).
 *
 * @property int $id
 * @property bool $success
 * @property int $consecutive_failures
 * @property string|null $error
 * @property Carbon $ran_at
 */
class ZabbixSyncLog extends Model
{
    /** Consecutive failures that trigger the admin alert (issue #740). */
    public const ALERT_THRESHOLD = 3;

    public $timestamps = false;

    protected $fillable = ['success', 'consecutive_failures', 'error', 'ran_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'success' => 'boolean',
        'consecutive_failures' => 'integer',
        'ran_at' => 'datetime',
    ];
}
