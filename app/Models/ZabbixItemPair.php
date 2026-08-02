<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZabbixItemPair extends Model
{
    protected $fillable = [
        'zabbix_host_id',
        'name',
        'out_item_id',
        'in_item_id',
        'unit_id',
        'description',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(ZabbixHost::class, 'zabbix_host_id');
    }

    public function outItem(): BelongsTo
    {
        return $this->belongsTo(ZabbixItem::class, 'out_item_id');
    }

    public function inItem(): BelongsTo
    {
        return $this->belongsTo(ZabbixItem::class, 'in_item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}