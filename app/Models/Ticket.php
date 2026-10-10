<?php

namespace App\Models;

use App\Traits\HasOrganizationalScope;
use Carbon\Carbon;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $ticket_code
 * @property int|null $user_id
 * @property int $unit_id
 * @property string $subject
 * @property string $content
 * @property string $priority
 * @property string $status
 * @property int|null $current_assignee_id
 * @property int $task_id
 * @property Carbon|null $deadline
 * @property Carbon|null $accepted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $status_name
 * @property-read array $waiting_duration
 * @property-read Unit $unit
 * @property-read User $user
 * @property-read User|null $assignee
 * @property-read Todo|null $task
 * @property-read Collection $attachments
 * @property-read Collection $activities
 * @property-read Collection $comments
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, HasOrganizationalScope;

    protected $fillable = [
        'ticket_code',
        'user_id',
        'unit_id',
        'subject',
        'content',
        'priority',
        'status',
        'task_id',
        'deadline',
        'current_assignee_id',
        'accepted_at',
        'completed_at',
        'archived_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'deadline' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function canBeCompleted()
    {
        return $this->status === 'accepted';
    }

    public function getWaitingDurationAttribute()
    {
        $totalHours = floor($this->created_at->diffInHours(now()));

        if ($totalHours < 1) {
            return ['text' => 'کمتر از ۱ ساعت', 'class' => 'bg-emerald-100 text-emerald-700'];
        }

        if ($totalHours < 24) {
            return ['text' => $totalHours.' ساعت', 'class' => 'bg-emerald-100 text-emerald-700'];
        } elseif ($totalHours < 48) {
            return ['text' => '۱ روز و '.($totalHours - 24).' ساعت', 'class' => 'bg-orange-100 text-orange-700'];
        } else {
            $days = floor($totalHours / 24);

            return ['text' => $days.' روز و '.($totalHours % 24).' ساعت', 'class' => 'bg-red-100 text-red-700 animate-pulse'];
        }
    }

    // تعریف status_name برای نمایش فارسی وضعیت‌های تیکت
    public function getStatusNameAttribute()
    {
        return match ($this->status) {
            'created' => 'جدید',
            'forwarded' => 'ارجاع شده',
            'accepted' => 'در حال پیگیری',
            'completed' => 'پایان یافته',
            'rejected' => 'رد شده',
            default => 'نامشخص',
        };
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    // رابطه با وظیفه مرتبط
    public function task(): BelongsTo
    {
        return $this->belongsTo(Todo::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // رابطه با شخصی که تیکت به او واگذار شده
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_assignee_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    // رابطه با فعالیت‌ها
    public function activities()
    {
        // دقت کنید که در مایگریشن جدید نام فیلد را ticket_id گذاشتیم
        return $this->hasMany(TaskActivity::class, 'ticket_id');
    }

    // رابطه با نظرات
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /**
     * Issue #880 — delete the attachment FILES with the ticket.
     *
     * `attachments.ticket_id` is `ON DELETE CASCADE`, so deleting a ticket
     * erased every attachment row — and with it the only database record of
     * each file. The file itself stayed under `storage/app/public/attachments/`,
     * still reachable at its public URL forever, with no row left to locate or
     * purge it.
     *
     * This runs on `deleting`, not `deleted`: the FK cascade happens in the
     * database AFTER Eloquent's events, so by `deleted` the paths are already
     * gone and a hook on `Attachment` would never have fired on this path at
     * all.
     *
     * The rows are deleted explicitly (before the cascade) so the file and its
     * row disappear as one unit. `config/filesystems.php` sets `throw => false`
     * on the `public` disk, so a row whose file was already removed by hand
     * still deletes cleanly.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $ticket): void {
            $attachments = $ticket->attachments()->get(['id', 'file_path']);

            $paths = $attachments
                ->pluck('file_path')
                ->filter(fn (?string $path): bool => filled($path))
                ->values()
                ->all();

            if ($paths !== []) {
                Storage::disk('public')->delete($paths);
            }

            $ids = $attachments->pluck('id')->all();
            if ($ids !== []) {
                Attachment::query()->whereIn('id', $ids)->delete();
            }
        });
    }
}
