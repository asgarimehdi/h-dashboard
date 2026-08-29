<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceSchedule extends Model
{
    protected $fillable = [
        'unit_id',
        'title',
        'frequency',
        'recurrence_interval',
        'last_generated_at',
        'next_due_at',
    ];

    protected $casts = [
        'recurrence_interval' => 'integer',
        'last_generated_at' => 'datetime',
        'next_due_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * آیا زمانبندی سررسید شده است؟
     */
    public function isDue(): bool
    {
        return $this->next_due_at === null || $this->next_due_at->lte(now());
    }
}
