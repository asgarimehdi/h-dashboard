<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $user_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $url
 * @property array<string, mixed>|null $data
 * @property string|null $dedupe_key
 * @property bool $is_read
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> where(string $column, mixed $value)
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if (! $model->id) {
                $model->id = Str::uuid();
            }
        });

        // Issue #1348: every Eloquent write to a notification bumps the
        // `notifications` namespace the bell component reads, and the
        // `tools_stats` namespace whose counts include notifications.
        // Query-builder writes bypass these hooks — markAllAsRead() bumps
        // explicitly, and the bell component and NotificationService bump
        // their own query-builder paths.
        $bump = function (): void {
            $cache = app(CacheInvalidationServiceInterface::class);
            $cache->increment('notifications');
            $cache->increment('tools_stats');
        };

        static::created($bump);
        static::updated($bump);
        static::deleted($bump);
    }

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'icon',
        'color',
        'url',
        'data',
        'dedupe_key',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsRead(): void
    {
        $this->update(['is_read' => true, 'read_at' => now()]);
    }

    public static function markAllAsRead(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user) {
            static::where('user_id', $user->id)->where('is_read', false)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            // Query-builder update fires no model event — bump explicitly
            // (issue #1348), so the bell cache cannot stay stale. Read-state
            // changes do not move the tools counts, so only `notifications`.
            app(CacheInvalidationServiceInterface::class)->increment('notifications');
        }
    }
}
