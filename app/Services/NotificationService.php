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
    public static function send(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        string $icon = 'o-bell',
        string $color = 'text-info',
        ?string $url = null,
        ?array $data = null
    ): NotificationModel {
        $notification = NotificationModel::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'icon' => $icon,
            'color' => $color,
            'url' => $url,
            'data' => $data,
        ]);

        // Issue #393: invalidate the bell component cache for the recipient
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
