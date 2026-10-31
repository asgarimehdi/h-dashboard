<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Notification as NotificationModel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Create a notification for a user.
     *
     * `$dedupeKey` is the identity of the event that produced the notification,
     * not a hash of its content: every retry (or duplicate delivery) of the same
     * event must pass the same key, and different events must pass different
     * keys. A second send with the same key on the same user returns the row
     * that already exists instead of inserting another one.
     *
     * Rules:
     * - Empty string is treated as "no key" (falls back to a plain insert).
     * - The key is at most 191 characters; longer keys are rejected with an
     *   InvalidArgumentException rather than silently truncated.
     * - There is no separate TTL: the key lives exactly as long as its
     *   notification row (CleanNotificationsJob deletes both together), so any
     *   time window belongs in the key itself.
     */
    public static function send(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        string $icon = 'o-bell',
        string $color = 'text-info',
        ?string $url = null,
        ?array $data = null,
        ?string $dedupeKey = null,
    ): NotificationModel {
        if ($dedupeKey === '') {
            $dedupeKey = null;
        }

        if ($dedupeKey !== null && mb_strlen($dedupeKey) > 191) {
            throw new \InvalidArgumentException('Notification dedupe key must be 191 characters or fewer.');
        }

        $attributes = [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'icon' => $icon,
            'color' => $color,
            'url' => $url,
            'data' => $data,
        ];

        if ($dedupeKey === null) {
            $notification = NotificationModel::create($attributes);
        } else {
            $notification = NotificationModel::query()->firstOrCreate(
                ['user_id' => $userId, 'dedupe_key' => $dedupeKey],
                $attributes,
            );
        }

        // Issue #393: invalidate the bell component cache for the recipient.
        // Deliberately unconditional (also on the deduped path) and deliberately
        // not made non-fatal: a failed invalidation throws, and the retry hits
        // the existing row again and repairs the stale cache.
        Cache::forget("notifications:user:{$userId}");

        return $notification;
    }

    public static function notifyUnit(int $unitId, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        $userIds = User::whereHas('units', fn ($q) => $q->where('units.id', $unitId))
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        // Batch insert for better performance (must include UUID since insert()
        // bypasses model boot). It also bypasses Eloquent events entirely — no
        // model is instantiated, so no observer/listener/broadcast fires after
        // it; if Notification ever gains one, this path silently skips it and
        // needs its own review.
        $notifications = $userIds->map(fn ($userId) => [
            'id' => Str::uuid(),
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'icon' => 'o-ticket',
            'color' => 'text-info',
            'url' => $url,
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        Notification::insert($notifications);

        // Issue #393: invalidate the bell cache for all recipients. Issue #1304:
        // defer the Redis round-trips behind afterCommit so they never run while
        // a transaction is open (and are dropped on rollback). Outside a
        // transaction afterCommit runs immediately, keeping the old behaviour.
        DB::afterCommit(function () use ($userIds) {
            foreach ($userIds as $userId) {
                Cache::forget("notifications:user:{$userId}");
            }
        });
    }
}
