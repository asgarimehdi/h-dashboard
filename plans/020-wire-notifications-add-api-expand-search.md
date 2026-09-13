# Plan 020: Wire browser notifications + add notification API + expand global search

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 5f9c24e..HEAD -- routes/api.php resources/views/livewire/notifications/bell.blade.php resources/views/livewire/search/index.blade.php resources/views/components/layouts/app.blade.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: direction
- **Planned at**: commit `5f9c24e`, 2026-09-12

## Why this matters

Three gaps create a disconnected notification and search experience:

1. **Browser notifications are inert**: The `browser-notification` Livewire event is only dispatched from `sendTestNotification()` in settings. The bell component (`notifications/bell.blade.php`) loads notifications via server-side cache but never pushes them to the browser. Users never see native browser notifications for real events.

2. **Flutter app is blind**: The Flutter mobile app authenticates via `/api/login` and consumes API routes, but there are zero notification endpoints in `routes/api.php`. The app has no way to list or mark notifications as read.

3. **Global search misses 767 records**: The search component queries tickets, todos, users, and units — but NOT hardware (449 devices) or persons (318 records). Users searching for a device name or person name get zero results.

## Current state

### Bell component (`resources/views/livewire/notifications/bell.blade.php`)

```php
public function mount(): void
{
    $this->loadNotifications();
}

public function loadNotifications(): void
{
    $userId = auth()->id();
    $cacheKey = 'notifications:user:' . $userId;
    $cached = Cache::remember($cacheKey, 60, function () use ($userId) {
        $notifications = Notification::where('user_id', $userId)
            ->select('id', 'type', 'title', 'body', 'icon', 'color', 'url', 'is_read', 'created_at')
            ->latest()->take(15)->get();
        $unreadCount = Notification::where('user_id', $userId)
            ->where('is_read', false)->count();
        return ['notifications' => $notifications, 'unreadCount' => $unreadCount];
    });
    $this->notifications = $cached['notifications'];
    $this->unreadCount = $cached['unreadCount'];
}
```

No polling. No browser-notification dispatch. The component loads once on mount and caches for 60s.

### Browser notification listener (`resources/views/components/layouts/app.blade.php`, line 337)

```javascript
Livewire.on('browser-notification', (data) => {
    if ('Notification' in window && Notification.permission === 'granted') {
        new Notification(data[0].title, { body: data[0].body });
    }
});
```

This listener exists and works, but nothing dispatches the event except the test button.

### NotificationService (`app/Services/NotificationService.php`)

```php
class NotificationService
{
    public static function send(int $userId, string $type, string $title, ...): NotificationModel
    public static function notifyUnit(int $unitId, string $type, string $title, ...): void
}
```

Called from `TicketCommentController` for comments, mentions, reactions, ticket creation/accept/complete. NOT called for assignments, hardware changes, maintenance tickets, or todo creation.

### Notification Model (`app/Models/Notification.php`)

UUID primary key, `user_id`, `type`, `title`, `body`, `icon`, `color`, `url`, `data`, `is_read`, `read_at`. Belongs to `User`.

### API routes (`routes/api.php`)

No `/api/notifications` routes exist. All other entities (tickets, hardware, persons, todos, units, HR, GIS) have API endpoints.

### Global search (`resources/views/livewire/search/index.blade.php`)

```php
public string $query = '';
public array $results = ['tickets' => [], 'todos' => [], 'users' => [], 'units' => []];

public function search(): void
{
    // ... searches: tickets, todos, users, units
    // No hardware or person search
}
```

Results array only has 4 keys: `tickets`, `todos`, `users`, `units`. No `hardware` or `persons` keys.

## Commands you will need

| Purpose              | Command                                                  | Expected on success                    |
|----------------------|----------------------------------------------------------|----------------------------------------|
| Drift check          | `git diff --stat 5f9c24e..HEAD -- routes/api.php`       | empty or minimal                       |
| Verify API routes    | `php artisan route:list --path=api/notifications`        | shows index, mark-as-read routes       |
| Verify search        | `grep -n 'hardware.*\|persons' resources/views/livewire/search/index.blade.php` | shows new queries   |
| Run search E2E       | `npx playwright test tests/e2e/search/global.spec.ts`    | tests pass                             |

## Scope

**In scope** (the only files you should modify):
- `resources/views/livewire/notifications/bell.blade.php` — add polling + browser notification dispatch
- `routes/api.php` — add notification API routes
- `app/Http/Controllers/Api/NotificationController.php` (create) — API endpoints
- `resources/views/livewire/search/index.blade.php` — add hardware + persons queries
- `resources/views/livewire/search/index.blade.php` (Blade template) — add hardware + persons result sections

**Out of scope**:
- `NotificationService` changes — it already works correctly for the events it handles
- Expanding `NotificationService::send()` calls to cover hardware/todo events — that's a separate scope
- Push notification infrastructure (FCM, etc.) — beyond current architecture
- Real-time websockets for notifications — Livewire polling is sufficient for now

## Git workflow

- Branch: `celin`
- Commit messages:
  - `feat(notifications): add polling to bell + dispatch browser notifications`
  - `feat(api): add notification list and mark-as-read endpoints`
  - `feat(search): expand global search to include hardware and persons`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add Livewire polling to the bell component + dispatch browser notifications

In `resources/views/livewire/notifications/bell.blade.php`:

**1a. Add `#[On('timerSecond')]` or use Livewire's built-in polling.** The simplest approach is to add `wire:poll.60s="loadNotifications"` to the root div. This reloads notifications every 60 seconds. However, this creates a server round-trip even when nothing changed.

Better approach: add polling AND dispatch browser notifications for new unread items.

```php
// Add a property to track the last known unread count
public int $lastUnreadCount = 0;
```

Modify `loadNotifications()` to detect new notifications and dispatch browser events:

```php
public function loadNotifications(): void
{
    $userId = auth()->id();
    $cacheKey = 'notifications:user:' . $userId;
    $cached = Cache::remember($cacheKey, 60, function () use ($userId) {
        $notifications = Notification::where('user_id', $userId)
            ->select('id', 'type', 'title', 'body', 'icon', 'color', 'url', 'is_read', 'created_at')
            ->latest()->take(15)->get();
        $unreadCount = Notification::where('user_id', $userId)
            ->where('is_read', false)->count();
        return ['notifications' => $notifications, 'unreadCount' => $unreadCount];
    });
    $this->notifications = $cached['notifications'];

    // Detect new notifications and dispatch browser event
    $newUnread = $cached['unreadCount'];
    if ($newUnread > $this->lastUnreadCount && $this->lastUnreadCount > 0) {
        // Find the newest notification to display
        $newest = $this->notifications->firstWhere('is_read', false);
        if ($newest) {
            $this->dispatch('browser-notification', [
                'title' => $newest['title'],
                'body' => $newest['body'] ?? '',
                'url' => $newest['url'] ?? '/dashboard',
            ]);
        }
    }
    $this->lastUnreadCount = $newUnread;
    $this->unreadCount = $newUnread;
}
```

In the Blade template, add polling to the root div:
```blade
<div class="relative" wire:click.away="$set('showDropdown', false)" wire:poll.60s="loadNotifications">
```

**Verify**: `grep -n 'wire:poll' resources/views/livewire/notifications/bell.blade.php` → shows `wire:poll.60s="loadNotifications"`.

### Step 2: Create NotificationController for API

Create `app/Http/Controllers/Api/NotificationController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->select('id', 'type', 'title', 'body', 'icon', 'color', 'url', 'is_read', 'created_at')
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($notifications);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'marked as read']);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'all marked as read']);
    }
}
```

**Verify**: `php artisan route:list --path=api/notifications` shows the three new routes.

### Step 3: Register notification API routes

In `routes/api.php`, inside the authenticated middleware group (after the GIS routes, before the closing `});`), add:

```php
// Notification API routes
Route::prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('/{id}/mark-as-read', [NotificationController::class, 'markAsRead']);
    Route::post('/mark-all-as-read', [NotificationController::class, 'markAllAsRead']);
});
```

Add `use App\Http\Controllers\Api\NotificationController;` to the imports at the top.

**Verify**: `php artisan route:list --path=api/notifications` → shows 3 routes (GET /, POST /{id}/mark-as-read, POST /mark-all-as-read).

### Step 4: Expand global search to include hardware

In `resources/views/livewire/search/index.blade.php`:

**4a. Add `use App\Models\Hardware;`** to the imports at the top.

**4b. Add hardware to the results array:**
```php
public array $results = ['tickets' => [], 'todos' => [], 'users' => [], 'units' => [], 'hardware' => [], 'persons' => []];
```

**4c. Add hardware query in the `search()` method**, inside the `Cache::remember` callback, after the `units` query:

```php
'hardware' => Hardware::whereHas('person', fn ($hwq) => $hwq->whereIn('u_id', $accessibleIds))
    ->where(function ($query) use ($words) {
        foreach ($words as $word) {
            $query->where(function ($inner) use ($word) {
                $inner->where('pc_name', 'like', "%{$word}%")
                    ->orWhere('ip_valid', 'like', "%{$word}%")
                    ->orWhere('ip_local', 'like', "%{$word}%")
                    ->orWhere('mac', 'like', "%{$word}%");
            });
        }
    })
    ->with('person')
    ->latest()
    ->take(10)
    ->get()
    ->toArray(),
```

**4d. Add persons query** (searching Person model directly, not through User):

```php
'persons' => Person::whereIn('u_id', $accessibleIds)
    ->where(function ($query) use ($words) {
        foreach ($words as $word) {
            $query->where(function ($inner) use ($word) {
                $inner->where('f_name', 'like', "%{$word}%")
                    ->orWhere('l_name', 'like', "%{$word}%")
                    ->orWhere('n_code', 'like', "%{$word}%");
            });
        }
    })
    ->with('unit')
    ->latest()
    ->take(10)
    ->get()
    ->toArray(),
```

Add `use App\Models\{Person};` to the imports.

**4e. Update `getTotalCount()`** to include the new result types:
```php
public function getTotalCount(): int
{
    return count($this->results['tickets'])
        + count($this->results['todos'])
        + count($this->results['users'])
        + count($this->results['units'])
        + count($this->results['hardware'])
        + count($this->results['persons']);
}
```

**Verify**: `grep -n 'hardware' resources/views/livewire/search/index.blade.php` → shows the new Hardware query.

### Step 5: Add hardware and persons result sections to the Blade template

In the Blade portion of `search/index.blade.php`, after the `units` section and before the `todos` section, add:

**Hardware section:**
```blade
{{-- سخت‌افزار --}}
@if(count($results['hardware']))
    <div class="mb-6">
        <h3 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-cpu-chip" class="w-5 h-5" />
            سخت‌افزار
            <span class="badge badge-sm">{{ count($results['hardware']) }}</span>
        </h3>
        <div class="space-y-2">
            @foreach($results['hardware'] as $hw)
                <a href="/hardware"
                   wire:navigate
                   class="flex items-center justify-between p-3 rounded-lg bg-base-200 hover:bg-base-300 transition">
                    <div class="flex items-center gap-3">
                        <x-icon name="o-cpu-chip" class="w-5 h-5 text-primary" />
                        <div>
                            <div class="font-medium">{{ $hw['pc_name'] }}</div>
                            <div class="text-xs text-base-content/50">
                                صاحب: {{ $hw['person']['f_name'] ?? '' }} {{ $hw['person']['l_name'] ?? '' }}
                                @if($hw['ip_local']) | IP: {{ $hw['ip_local'] }} @endif
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endif
```

**Persons section:**
```blade
{{-- پرسنل --}}
@if(count($results['persons']))
    <div class="mb-6">
        <h3 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-user-group" class="w-5 h-5" />
            پرسنل
            <span class="badge badge-sm">{{ count($results['persons']) }}</span>
        </h3>
        <div class="space-y-2">
            @foreach($results['persons'] as $person)
                <a href="/hardware"
                   wire:navigate
                   class="flex items-center gap-3 p-3 rounded-lg bg-base-200 hover:bg-base-300 transition">
                    <x-icon name="o-user-circle" class="w-8 h-8 text-primary" />
                    <div>
                        <div class="font-medium">{{ $person['f_name'] }} {{ $person['l_name'] }}</div>
                        <div class="text-xs text-base-content/50">
                            کد ملی: {{ $person['n_code'] }}
                            @if(isset($person['unit']['name'])) | {{ $person['unit']['name'] }} @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endif
```

Also update the empty-state placeholder text to include hardware and persons:
```blade
<p class="text-lg text-base-content/50">جستجو در تیکت‌ها، کاربران، واحدها، سخت‌افزار، پرسنل و کارهای روزانه</p>
```

**Verify**: `grep -n 'سخت‌افزار\|پرسنل' resources/views/livewire/search/index.blade.php` → shows both sections in the Blade template.

## Test plan

### New E2E test additions

Update `tests/e2e/search/global.spec.ts` to add:

1. **Hardware search returns results**: type a known hardware name (e.g., first record's `pc_name`), verify "سخت‌افزار" section appears.
2. **Person search returns results**: type a known person name, verify "پرسنل" section appears.

### API test

Verify via `curl` or Playwright:
```
GET /api/notifications → 200 with paginated notifications
POST /api/notifications/{id}/mark-as-read → 200
POST /api/notifications/mark-all-as-read → 200
```

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -n 'wire:poll' resources/views/livewire/notifications/bell.blade.php` → shows polling
- [ ] `grep -n 'browser-notification' resources/views/livewire/notifications/bell.blade.php` → shows dispatch call
- [ ] `php artisan route:list --path=api/notifications` exits 0 and shows 3 routes
- [ ] `grep -n 'hardware' resources/views/livewire/search/index.blade.php` → shows Hardware model usage
- [ ] `grep -n 'persons' resources/views/livewire/search/index.blade.php` → shows Person model usage (in search query, not just template)
- [ ] `npx playwright test tests/e2e/search/global.spec.ts` passes
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at the locations in "Current state" doesn't match the excerpts (the codebase has drifted since this plan was written).
- The `Notification` model doesn't have a `user_id` column or the API routes conflict with existing routes.
- The search component's `Cache::remember` key doesn't include the new result types — stale cache would hide new results. (Ensure the cache key hashes all result keys or clear cache during development.)
- The bell component's polling causes excessive server load — reduce polling interval or use WebSocket if needed.
- The Hardware or Person models don't have the expected relationships (`person`, `unit`).

## Maintenance notes

- The 60-second polling interval is a compromise between freshness and server load. For real-time needs, consider Livewire Echo or WebSocket integration in the future.
- The API notification endpoints don't require any permission middleware beyond `auth:sanctum` — any authenticated user can read their own notifications. This matches the web UI behavior.
- The `persons` search result links to `/hardware` (filtered by person). If a dedicated person detail page is added later, update the link.
- Cache key for search results includes query + unit IDs + user IDs — it does NOT include the new hardware/person result types. If hardware/person data changes frequently, consider adding a version suffix or reducing TTL.
- The `NotificationService::send()` is NOT modified in this plan — expanding it to cover hardware/todo events is explicitly deferred.
