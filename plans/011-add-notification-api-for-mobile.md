# Plan 011: Add Notification API endpoints for Flutter

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: direction
- **Planned at**: 2026-09-19

## Why this matters
Web UI has a notifications bell component but Flutter mobile app has zero notification API routes. Mobile users receive notifications but cannot read, list, or mark them as read.

## Current state
- `app/Models/Notification.php`: full model with markAsRead()
- Web: `resources/views/livewire/notifications/bell.blade.php`
- API: zero notification routes in `routes/api.php`

## Steps

### Step 1: Create NotificationController
Create `app/Http/Controllers/Api/NotificationController.php` with:
- `index()`: list user notifications (paginated, newest first)
- `unreadCount()`: return count of unread
- `markAsRead($id)`: mark single notification read
- `markAllRead()`: mark all user notifications read

### Step 2: Add routes to api.php
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});
```

### Step 3: Verify
**Verify**: `cd /home/runner/h-dashboard && grep -n "notifications" routes/api.php` → should show routes

## Done criteria
- [ ] 4 notification API endpoints
- [ ] Auth-gated with sanctum
- [ ] pint passes
