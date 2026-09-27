<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $unit_id
 * @property string $title
 * @property string $frequency
 * @property int $recurrence_interval
 * @property Carbon|null $last_generated_at
 * @property Carbon|null $next_due_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit|null $unit
 *
 * @method static Builder<static> where(string $column, mixed $value)
 */
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
