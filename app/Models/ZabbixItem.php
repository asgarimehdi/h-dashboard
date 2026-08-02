<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZabbixItem extends Model
{
    protected $fillable = [
        'zabbix_host_id',
        'item_id',
        'item_key',
        'name',
        'type',
        'unit',
        'value_type',
        'delay',
        'is_monitored',
        'display_order',
        'last_value',
        'last_check_at',
    ];

    protected $casts = [
        'is_monitored' => 'boolean',
        'last_value' => 'array',
        'last_check_at' => 'datetime',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(ZabbixHost::class, 'zabbix_host_id');
    }

    public function outPairs(): HasMany
    {
        return $this->hasMany(ZabbixItemPair::class, 'out_item_id');
    }

    public function inPairs(): HasMany
    {
        return $this->hasMany(ZabbixItemPair::class, 'in_item_id');
    }
}