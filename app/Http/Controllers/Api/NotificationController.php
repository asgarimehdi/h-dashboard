<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $userId = Auth::id();
        $notifications = Notification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(20);

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(): JsonResponse
    {
        $userId = Auth::id();
        $count = Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(string $id): JsonResponse
    {
        $userId = Auth::id();
        $notification = Notification::where('id', $id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        Notification::markAllAsRead();

        return response()->json(['success' => true]);
    }
}
