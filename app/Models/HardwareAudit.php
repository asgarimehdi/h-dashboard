<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareAudit extends Model
{
    protected $fillable = [
        'hardware_id',
        'user_id',
        'action',
        'changes',
        'source',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function hardware(): BelongsTo
    {
        return $this->belongsTo(Hardware::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
