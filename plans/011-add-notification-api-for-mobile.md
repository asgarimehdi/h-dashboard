# Plan 011: Add Notification API endpoints for Flutter

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: direction
- **Planned at**: 2026-09-19 (v2, verified)

## Why this matters
Web UI has a notifications bell component (`resources/views/livewire/notifications/bell.blade.php`) but Flutter mobile app has zero notification API routes. Mobile users receive notifications but cannot read, list, or mark them as read.

## Current state (verified)
- `app/Models/Notification.php`: full model with `markAsRead()` (line 49), `markAllAsRead()` (line 54), `user()` relation
- Web: `resources/views/livewire/notifications/bell.blade.php`
- API: zero notification routes in `routes/api.php`

## Steps

### Step 1: Create NotificationController
Create `app/Http/Controllers/Api/NotificationController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($notifications);
    }

    public function unreadCount()
    {
        $count = Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(string $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead()
    {
        Notification::markAllAsRead();

        return response()->json(['success' => true]);
    }
}
```

### Step 2: Add routes to api.php
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);
});
```

### Step 3: Add test
Create `tests/Feature/NotificationApiTest.php` (Pest):
- test user can list notifications
- test user can get unread count
- test user can mark notification as read
- test user cannot mark other user's notification as read
- test user can mark all as read

### Step 4: Verify
```bash
grep -n "notifications" routes/api.php
# → should show 4 routes
composer test -- --filter=NotificationApiTest
# → passes
```

## Done criteria
- [ ] 4 notification API endpoints
- [ ] Auth-gated with sanctum
- [ ] Pest tests added and passing
- [ ] `vendor/bin/pint --dirty --format agent` passes
