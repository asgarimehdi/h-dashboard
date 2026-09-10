# Plan 003: Dashboard Auto-Refresh

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c775883..HEAD -- resources/views/livewire/dashboard.blade.php resources/views/livewire/settings/index.blade.php`
> If any of these changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: feature
- **Planned at**: commit `c775883`, 2026-09-03

## Why this matters

The settings page has a "dashboardRefresh" dropdown that saves to `users.settings['dashboard_refresh']` with values 0/15/30/60 (seconds), but **the dashboard page never reads this value or refreshes automatically**. Users select "every 30 seconds" and expect the dashboard to update — nothing happens. This is a dead feature.

## Current state

- `resources/views/livewire/settings/index.blade.php` — dropdown at line 70, saves via `save()` method
- `resources/views/livewire/dashboard.blade.php` — dashboard page, no auto-refresh logic
- `app/Models/User.php` — has `settings` cast to array
- Dashboard is a Livewire component with `mount()` method

## Scope

**In scope:**
- `resources/views/livewire/dashboard.blade.php` (add Alpine.js auto-refresh)
- `resources/views/livewire/settings/index.blade.php` (add test button)
- `tests/Feature/SettingsDashboardRefreshTest.php` (new)

**Out of scope:**
- Email notifications (Plan 001)
- Browser notifications (Plan 002)
- Compact mode (Plan 004)

## Git workflow

- Branch: `it-test5`
- Commit per step; messages follow conventional style
- Push after each commit

## Steps

### Step 1: Read user setting in dashboard mount

In `resources/views/livewire/dashboard.blade.php`, read the refresh interval:

```php
// At the top of the Livewire class, add:
public int $refreshInterval = 0;

public function mount(): void
{
    $settings = auth()->user()->settings ?? [];
    $this->refreshInterval = $settings['dashboard_refresh'] ?? 0;
    // ... existing mount logic
}
```

**Verify**: `php artisan tinker --execute 'echo "OK";'` → `OK`

### Step 2: Add Alpine.js auto-refresh in dashboard blade

In `resources/views/livewire/dashboard.blade.php`, wrap the main content with Alpine.js timer:

```html
<div wire:poll.{{ $refreshInterval }}s="{{ $refreshInterval > 0 ? 'refresh' : '' }}">
    {{-- existing dashboard content --}}
</div>
```

Or use Alpine.js for more control:

```html
<div x-data="{ interval: {{ $refreshInterval * 1000 }} }" x-init="if(interval > 0) { setInterval(() => { $wire.refresh() }, interval) }">
    {{-- existing dashboard content --}}
</div>
```

**Verify**: `npm run build` → success

### Step 3: Add refresh method

In `resources/views/livewire/dashboard.blade.php`, add:

```php
public function refresh(): void
{
    // Re-fetch dashboard data
    $this->mount();
}
```

**Verify**: `php artisan tinker --execute 'echo "OK";'` → `OK`

### Step 4: Add test button in settings

In `resources/views/livewire/settings/index.blade.php`, add:

```php
// In the Livewire class, add:
public function testDashboardRefresh(): void
{
    if ($this->dashboardRefresh === 0) {
        $this->error('بروزرسانی خودکار غیرفعال است', position: 'toast-bottom');
        return;
    }

    $this->success("داشبورد هر {$this->dashboardRefresh} ثانیه بروزرسانی می‌شود", position: 'toast-bottom');
}
```

Add blade:
```html
<x-button label="تست بروزرسانی" icon="o-arrow-path" wire:click="testDashboardRefresh" class="btn-outline btn-sm" spinner />
```

**Verify**: Page loads without errors

### Step 5: Write tests

Create `tests/Feature/SettingsDashboardRefreshTest.php`:

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

class SettingsDashboardRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_dashboard_refresh_setting_persists(): void
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
            ->set('dashboardRefresh', 30)
            ->call('save');

        $user->refresh();
        $this->assertEquals(30, $user->settings['dashboard_refresh']);
    }

    public function test_dashboard_reads_refresh_setting(): void
    {
        $unit = Unit::create(['name' => 'تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->settings = ['dashboard_refresh' => 60];
        $user->save();
        $this->actingAs($user);

        Livewire::test('dashboard.index')
            ->assertSet('refreshInterval', 60);
    }
}
```

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/SettingsDashboardRefreshTest.php` → PASS

## Done criteria

- [ ] `composer test` exits 0
- [ ] Settings page has working dashboard refresh dropdown
- [ ] Dashboard reads `dashboard_refresh` setting
- [ ] Dashboard refreshes automatically when interval > 0
- [ ] Test button works
- [ ] Tests pass

## STOP conditions

- If `dashboard.blade.php` doesn't have a Livewire class
- If `dashboard_refresh` value is not in settings array
