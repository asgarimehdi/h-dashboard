<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkLink extends Model
{
    protected $fillable = [
        'source_switch',
        'target_switch',
        'link_type',
        'vlans',
        'distance_km',
        'latency_ms',
        'bandwidth_mbps',
        'is_redundant',
        'source_unit_id',
        'target_unit_id',
    ];

    protected $casts = [
        'vlans' => 'array',
        'is_redundant' => 'boolean',
        'distance_km' => 'float',
        'latency_ms' => 'integer',
        'bandwidth_mbps' => 'integer',
    ];

    public function sourceUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'source_unit_id');
    }

    public function targetUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'target_unit_id');
    }
}
