<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZabbixHost extends Model
{
    protected $fillable = [
        'unit_id',
        'hardware_id',
        'host_id',
        'host_name',
        'visible_name',
        'ip',
        'description',
        'status',
        'template_ids',
        'last_sync_at',
    ];

    protected $casts = [
        'template_ids' => 'array',
        'last_sync_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function hardware(): BelongsTo
    {
        return $this->belongsTo(Hardware::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ZabbixItem::class);
    }

    public function pairs(): HasMany
    {
        return $this->hasMany(ZabbixItemPair::class);
    }
}
