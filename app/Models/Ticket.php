<?php

namespace App\Models;

use App\Support\AttachmentFiles;
use App\Traits\HasOrganizationalScope;
use Carbon\Carbon;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property int|null $schedule_id
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

    /**
     * Canonical app-level ticket priority vocabulary (#954).
     *
     * The DB CHECK constraint deliberately accepts five values (`medium` and
     * `high` included) but every UI bucket, aggregation and validation rule
     * understands only these three — so this constant, not the DB, is the
     * vocabulary. The nightly `maintenance:generate-due` writer, the API
     * rules and the map-dashboard filter all read it; a value outside it is
     * storable but reportable by nothing.
     *
     * @var array<int, string>
     */
    public const PRIORITIES = ['low', 'normal', 'urgent'];

    /**
     * Canonical ticket status vocabulary (#1728).
     *
     * A ticket is CLOSED when its status is terminal or it is archived (#929:
     * archiving is a one-way timestamp). Everything else is OPEN, so these two
     * lists are the single definition of both — no caller re-spells the
     * predicate by hand. `scopeOpen()` serves Eloquent queries,
     * `openSql()` serves raw `DB::select` / `DB::table` aggregates.
     *
     * @var array<int, string>
     */
    public const CLOSED_STATUSES = ['completed', 'rejected'];

    /** @var array<int, string> */
    public const OPEN_STATUSES = ['created', 'forwarded', 'accepted'];

    protected $fillable = [
        'ticket_code',
        'user_id',
        'unit_id',
        'subject',
        'content',
        'priority',
        'status',
        'task_id',
        'schedule_id',
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

    /**
     * #1728: the one OPEN predicate for Eloquent queries — an open ticket has
     * a non-terminal status and is not archived. `archived_at IS NULL` is
     * load-bearing, not defensive: `TicketsInboxFlow::isClosedTicketRow()`
     * treats an archived row as closed regardless of its status.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereIn($query->qualifyColumn('status'), self::OPEN_STATUSES)
            ->whereNull($query->qualifyColumn('archived_at'));
    }

    /**
     * #1728: the same OPEN predicate as a raw SQL fragment, for the aggregates
     * that cannot call a scope (`DB::selectOne`, `DB::table`). `$table` is a
     * code-level table name such as `'tickets'`; the status literals come from
     * `OPEN_STATUSES`, never from user input.
     */
    public static function openSql(?string $table = null): string
    {
        $prefix = ($table === null || $table === '') ? '' : $table.'.';
        $statuses = implode(', ', array_map(
            static fn (string $status): string => "'".$status."'",
            self::OPEN_STATUSES,
        ));

        return $prefix.'status IN ('.$statuses.') AND '.$prefix.'archived_at IS NULL';
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
     * each file. The file itself stayed on disk, still reachable at its public
     * URL forever, with no row left to locate or purge it. (Issue #1276 moved
     * the files to the private `local` disk — `storage/app/private/attachments/`
     * — where nothing serves them without this hook running.)
     *
     * This runs on `deleting`, not `deleted`: the FK cascade happens in the
     * database AFTER Eloquent's events, so by `deleted` the paths are already
     * gone and a hook on `Attachment` would never have fired on this path at
     * all.
     *
     * The rows are deleted explicitly (before the cascade) so the file and its
     * row disappear as one unit. `config/filesystems.php` sets `throw => false`
     * on the `local` disk, so a row whose file was already removed by hand
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
                // The traversal guard (`..`) and the legacy-prefix
                // normalisation live in the shared helper (#1776).
                AttachmentFiles::delete($paths);
            }

            $ids = $attachments->pluck('id')->all();
            if ($ids !== []) {
                Attachment::query()->whereIn('id', $ids)->delete();
            }
        });
    }
}
