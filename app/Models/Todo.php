<?php

namespace App\Models;

use App\Traits\HasOrganizationalScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    use HasFactory;
    use HasOrganizationalScope;

    protected $fillable = [
        'title',
        'start_at',
        'end_at',
        'is_completed',
        'unit_id',
        'user_id',
        'recurrence_rule',
        'recurrence_interval',
        'last_generated_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_completed' => 'boolean',
        'recurrence_interval' => 'integer',
        'last_generated_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // تیکت‌های مرتبط با این وظیفه
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'task_id');
    }

    /**
     * آیا این وظیفه تکرارشونده است؟
     */
    public function isRecurring(): bool
    {
        return $this->recurrence_rule !== null && $this->recurrence_rule !== 'none';
    }

    /**
     * تاریخ سررسید بعدی برای تولید نمونه تکرارشونده.
     */
    public function nextOccurrence(): ?\Carbon\Carbon
    {
        if (! $this->isRecurring()) {
            return null;
        }

        $base = $this->last_generated_at ?? $this->start_at ?? now();
        $interval = max(1, (int) $this->recurrence_interval);

        return match ($this->recurrence_rule) {
            'daily' => $base->copy()->addDays($interval),
            'weekly' => $base->copy()->addWeeks($interval),
            'monthly' => $base->copy()->addMonths($interval),
            default => $base->copy()->addDays($interval),
        };
    }
}
