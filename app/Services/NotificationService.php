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
        foreach ($users as $user) {
            self::send($user->id, $type, $title, $body, 'o-ticket', 'text-info', $url);
        }
    }
}
