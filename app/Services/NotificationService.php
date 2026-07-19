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
        return NotificationModel::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'icon' => $icon,
            'color' => $color,
            'url' => $url,
            'data' => $data,
        ]);
    }

    public static function notifyUnit(int $unitId, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        $users = \App\Models\User::whereHas('units', fn($q) => $q->where('units.id', $unitId))->get();

        if ($users->isEmpty()) {
            return;
        }

        // Batch insert for better performance
        $notifications = $users->map(fn($user) => [
            'user_id' => $user->id,
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
    }
}
