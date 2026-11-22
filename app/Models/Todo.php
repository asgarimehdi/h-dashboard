<?php

namespace App\Models;

use App\Traits\HasOrganizationalScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property Carbon|null $start_at
 * @property Carbon|null $end_at
 * @property bool $is_completed
 * @property int|null $unit_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $user_id
 * @property string $recurrence_rule
 * @property int $recurrence_interval
 * @property Carbon|null $last_generated_at
 * @property int|null $template_id
 * @property-read Unit $unit
 * @property-read User $user
 * @property-read Collection<int, Ticket> $tickets
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 * @method static \Illuminate\Database\Eloquent\Builder<static> accessibleTo(array<int> $unitIds, ?int $userId)
 */
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
        'template_id',
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
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'task_id');
    }

    /**
     * Null-unit contract (#838, #917): a null-unit todo belongs to its
     * creator. Lists match a row in the actor's unit scope, or a unit-less
     * row the actor created. The `whereIn` stays unconditional inside the
     * group so an empty scope compiles to `0 = 1` (fail-closed, #813/#819).
     * A null `$userId` builds no `orWhere` branch: `where('user_id', null)`
     * would compile to `IS NULL` and return owner-less orphan rows.
     *
     * @param  Builder<Todo>  $query
     * @param  array<int>  $unitIds
     * @return Builder<Todo>
     */
    public function scopeAccessibleTo(Builder $query, array $unitIds, ?int $userId): Builder
    {
        return $query->where(function ($q) use ($unitIds, $userId): void {
            $q->whereIn('unit_id', $unitIds);

            if ($userId !== null) {
                $q->orWhere(fn ($qq) => $qq->whereNull('unit_id')->where('user_id', $userId));
            }
        });
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
    public function nextOccurrence(): ?Carbon
    {
        if (! $this->isRecurring()) {
            return null;
        }

        $base = $this->last_generated_at ?? $this->start_at ?? now();
        $interval = max(1, (int) $this->recurrence_interval);

        return match ($this->recurrence_rule) {
            'daily' => $base->copy()->addDays($interval),
            'weekly' => $base->copy()->addWeeks($interval),
            'monthly' => $this->addMonthsAnchored(
                $base,
                $interval,
                $this->start_at === null ? $base->day : $this->start_at->day,
            ),
            default => $base->copy()->addDays($interval),
        };
    }

    /**
     * گام ماهانهٔ بدون سرریز، لنگرشده روی روزِ اصلیِ تکرار (ایشو #1623).
     *
     * `addMonths` سرریز می‌کند (۳۱ ژانویه + ۱ ماه = ۳ مارس) و فوریه را
     * جا می‌اندازد. اینجا روزِ `start_at` لنگر می‌ماند و فقط در ماه‌های
     * کوتاه به آخرین روز clamp می‌شود، پس ۳۱ ژانویه → ۲۸ فوریه → ۳۱ مارس.
     */
    private function addMonthsAnchored(Carbon $base, int $months, int $anchorDay): Carbon
    {
        $next = $base->copy()->addMonthsNoOverflow($months);
        $next->setDate($next->year, $next->month, min($anchorDay, $next->daysInMonth));

        return $next;
    }
}
