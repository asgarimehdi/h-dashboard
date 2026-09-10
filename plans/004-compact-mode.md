# Plan 004: Compact Mode

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c775883..HEAD -- resources/views/livewire/settings/index.blade.php resources/views/components/layouts/app.blade.php public/css/app.css`
> If any of these changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: feature
- **Planned at**: commit `c775883`, 2026-09-03

## Why this matters

The settings page has a "compactMode" toggle that saves to `users.settings['compact_mode']` but **no CSS or layout logic reads this value to apply compact styling**. Users enable it and expect tighter spacing, smaller fonts, and denser layouts — nothing changes. This is a dead feature.

## Current state

- `resources/views/livewire/settings/index.blade.php` — toggle at line 79, saves via `save()` method
- `resources/views/components/layouts/app.blade.php` — main app layout
- `public/css/` — no compact mode CSS
- Tailwind CSS 4 — utility-first, can use `@apply` or dynamic classes

## Scope

**In scope:**
- `resources/views/livewire/settings/index.blade.php` (read setting, pass to layout)
- `resources/views/components/layouts/app.blade.php` (apply compact class)
- `resources/css/compact.css` (new — compact mode styles)
- `tests/Feature/SettingsCompactModeTest.php` (new)

**Out of scope:**
- Email notifications (Plan 001)
- Browser notifications (Plan 002)
- Dashboard auto-refresh (Plan 003)

## Git workflow

- Branch: `it-test5`
- Commit per step; messages follow conventional style
- Push after each commit

## Steps

### Step 1: Read user setting in layout

In `resources/views/components/layouts/app.blade.php`, read the compact mode setting:

```php
@php
    $compactMode = auth()->user()->settings['compact_mode'] ?? false;
@endphp
```

**Verify**: `php artisan tinker --execute 'echo "OK";'` → `OK`

### Step 2: Apply compact class to body

In `resources/views/components/layouts/app.blade.php`, add class to body:

```html
<body class="{{ $compactMode ? 'compact-mode' : '' }}">
```

**Verify**: Page loads without errors

### Step 3: Create compact mode CSS

Create `resources/css/compact.css`:

```css
/* Compact Mode - tighter spacing, smaller fonts, denser layouts */
.compact-mode {
    --spacing-unit: 0.25rem;
    font-size: 0.875rem;
}

.compact-mode .card {
    padding: 0.75rem;
}

.compact-mode .table td,
.compact-mode .table th {
    padding: 0.375rem 0.5rem;
}

.compact-mode .stat {
    padding: 0.5rem;
}

.compact-mode .badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
}

.compact-mode .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.compact-mode .input,
.compact-mode .select {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.compact-mode .space-y-4 > * + * {
    margin-top: 0.5rem;
}

.compact-mode .space-y-2 > * + * {
    margin-top: 0.25rem;
}
```

**Verify**: File exists at `resources/css/compact.css`

### Step 4: Import compact CSS

In `resources/css/app.css`, add:

```css
@import './compact.css';
```

**Verify**: `npm run build` → success

### Step 5: Add test button in settings

In `resources/views/livewire/settings/index.blade.php`, add:

```php
// In the Livewire class, add:
public function testCompactMode(): void
{
    if ($this->compactMode) {
        $this->success('حالت فشرده فعال شد', position: 'toast-bottom');
    } else {
        $this->success('حالت عادی فعال شد', position: 'toast-bottom');
    }
}
```

Add blade:
```html
<x-button label="تست نما" icon="o-eye" wire:click="testCompactMode" class="btn-outline btn-sm" spinner />
```

**Verify**: Page loads without errors

### Step 6: Write tests

Create `tests/Feature/SettingsCompactModeTest.php`:

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

class SettingsCompactModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_compact_mode_setting_persists(): void
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
            ->set('compactMode', true)
            ->call('save');

        $user->refresh();
        $this->assertTrue($user->settings['compact_mode']);
    }

    public function test_settings_page_has_compact_toggle(): void
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
            ->assertSet('compactMode', false);
    }
}
```

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/SettingsCompactModeTest.php` → PASS

## Done criteria

- [ ] `composer test` exits 0
- [ ] Settings page has working compact mode toggle
- [ ] Layout reads `compact_mode` setting
- [ ] Compact CSS applied when enabled
- [ ] Test button works
- [ ] Tests pass

## STOP conditions

- If `app.blade.php` doesn't support dynamic body classes
- If `compact_mode` value is not in settings array
- If Tailwind CSS doesn't support `@import`
