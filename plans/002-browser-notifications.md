# Plan 002: Browser Notifications

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c775883..HEAD -- resources/views/livewire/settings/index.blade.php resources/views/components/layouts/app.blade.php`
> If any of these changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: feature
- **Planned at**: commit `c775883`, 2026-09-03

## Why this matters

The settings page has a "browserNotifications" toggle that saves to `users.settings['browser_notifications']` but **no code requests notification permission or sends browser push notifications**. Users see a toggle, think browser notifications are controllable, but they can't actually enable or disable them. This is a UX lie — worse than not having the toggle at all.

## Current state

- `resources/views/livewire/settings/index.blade.php` — toggle exists at line 60, saves via `save()` method at line 25
- `app/Models/User.php` — has `settings` cast to array
- `resources/views/components/layouts/app.blade.php` — main app layout, no notification permission request
- No service worker or push notification infrastructure

## Scope

**In scope:**
- `resources/views/livewire/settings/index.blade.php` (add permission request on toggle)
- `resources/views/components/layouts/app.blade.php` (add notification permission check)
- `public/sw.js` (new — service worker for push notifications)
- `resources/views/livewire/settings/index.blade.php` (add debug test button)
- `tests/Feature/SettingsBrowserNotificationTest.php` (new)

**Out of scope:**
- Email notifications (Plan 001)
- Dashboard auto-refresh (Plan 003)
- Compact mode (Plan 004)
- Server-side push notification sending (requires FCM/VAPID setup)

## Git workflow

- Branch: `it-test5`
- Commit per step; messages follow conventional style
- Push after each commit

## Steps

### Step 1: Add notification permission request in settings

In `resources/views/livewire/settings/index.blade.php`, add Alpine.js logic to request permission when toggle is enabled:

```html
<label class="flex items-center justify-between cursor-pointer">
    <span>اعلان مرورگر</span>
    <input type="checkbox" class="toggle toggle-primary"
           wire:model.live="browserNotifications"
           x-on:change="if($event.target.checked && 'Notification' in window) { Notification.requestPermission().then(p => { if(p !== 'granted') { $wire.set('browserNotifications', false) } }) }" />
</label>
```

**Verify**: Page loads without JS errors

### Step 2: Add notification check in app layout

In `resources/views/components/layouts/app.blade.php`, add a script to check notification status:

```html
@push('scripts')
<script>
    document.addEventListener('livewire:init', () => {
        // Check if user has browser notifications enabled
        if ('Notification' in window && Notification.permission === 'granted') {
            // Notifications are available
            console.log('Browser notifications enabled');
        }
    });
</script>
@endpush
```

**Verify**: `npm run build` → success

### Step 3: Create service worker (placeholder)

Create `public/sw.js`:

```js
// Service worker for browser notifications
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'داشبورد سلامت';
    const options = {
        body: data.body || '',
        icon: data.icon || '/favicon.ico',
        badge: data.badge || '/favicon.ico',
        data: data.url || '/'
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data)
    );
});
```

**Verify**: File exists at `public/sw.js`

### Step 4: Register service worker in layout

In `resources/views/components/layouts/app.blade.php`, add registration:

```html
@push('scripts')
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }
</script>
@endpush
```

**Verify**: `npm run build` → success

### Step 5: Add test button in settings

In `resources/views/livewire/settings/index.blade.php`, add a "test notification" button:

```php
// In the Livewire class, add:
public function sendTestNotification(): void
{
    if (! $this->browserNotifications) {
        $this->error('اعلان مرورگر غیرفعال است', position: 'toast-bottom');
        return;
    }

    $this->dispatch('browser-notification', [
        'title' => 'تست اعلان',
        'body' => 'این یک اعلان تستی از داشبورد سلامت است.',
        'url' => '/dashboard',
    ]);

    $this->success('اعلان ارسال شد!', position: 'toast-bottom');
}
```

Add blade:
```html
<x-button label="تست اعلان" icon="o-bell" wire:click="sendTestNotification" class="btn-outline btn-sm" spinner />
```

Add JS listener:
```html
@push('scripts')
<script>
    Livewire.on('browser-notification', (data) => {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(data[0].title, { body: data[0].body });
        }
    });
</script>
@endpush
```

**Verify**: `php artisan tinker --execute 'echo "OK";'` → `OK`

### Step 6: Write tests

Create `tests/Feature/SettingsBrowserNotificationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

covers(User::class);

class SettingsBrowserNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_settings_page_has_notification_toggle(): void
    {
        $unit = Unit::create(['name' => 'تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertStatus(200)
            ->assertSet('browserNotifications', false);
    }

    public function test_browser_notification_setting_persists(): void
    {
        $unit = Unit::create(['name' => 'تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('browserNotifications', true)
            ->call('save');

        $user->refresh();
        $this->assertTrue($user->settings['browser_notifications']);
    }
}
```

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/SettingsBrowserNotificationTest.php` → PASS

## Done criteria

- [ ] `composer test` exits 0
- [ ] Settings page has working browser notification toggle
- [ ] Permission request fires when toggle enabled
- [ ] Service worker registered
- [ ] Test notification works
- [ ] Tests pass

## STOP conditions

- If `Notification` API is not available in test environment
- If `app.blade.php` doesn't support `@push('scripts')`
