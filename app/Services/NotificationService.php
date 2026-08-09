<?php

namespace App\Services;

use App\Models\Notification as NotificationModel;

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
        \Illuminate\Support\Facades\Cache::forget("notifications:user:{$userId}");

        return $notification;
    }

    public static function notifyUnit(int $unitId, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        $userIds = \App\Models\User::whereHas('units', fn($q) => $q->where('units.id', $unitId))
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        // Batch insert for better performance
        $notifications = $userIds->map(fn($userId) => [
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

        \App\Models\Notification::insert($notifications);

        // Issue #393: invalidate bell cache for all recipients
        foreach ($userIds as $userId) {
            \Illuminate\Support\Facades\Cache::forget("notifications:user:{$userId}");
        }
    }
}
