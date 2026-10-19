# perf: dashboard auto-refresh never fires ($wire.mount() is blocked)

> **Plan 001** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P1` · formerly GitHub issue #1137 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/dashboard.blade.php`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf (also a real bug)
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The dashboard's user-configurable auto-refresh (settings key `dashboard_refresh`) never refreshes anything: it calls `$wire.mount()`, and `mount` is one of Livewire's **protected lifecycle methods** — Livewire 4 refuses the call with `DirectlyCallingLifecycleHooksNotAllowedException`. Every interval tick therefore errors, and because the interval handle is never stored there is no `clearInterval`, so with `wire:navigate` the timer keeps firing at a destroyed component. Fixing this makes a shipped setting work again, removes a repeating error, and stops a timer leak. All of the dashboard's aggregation happens inside `mount()`, so a dedicated public refresh action is required — `$wire.$refresh()` alone would re-render without re-querying.

## Current state

- `resources/views/livewire/dashboard.blade.php` — the whole dashboard page (single-file Livewire component; PHP anonymous class at the top of the Blade file).
- All data loading is inside `mount()` (`:66`–`~:230`): cached aggregate queries (`dashboard:global`, `dashboard:stats`, `dashboard:today`, trend chart) assigned to public properties.
- The refresh wiring, `:340`:

```blade
<div x-data="{ interval: {{ $refreshInterval * 1000 }} }" x-init="if(interval > 0) { setInterval(() => { $wire.mount() }, interval) }">
```

- `refreshInterval` is a public int set from settings at `:63`/`:69`:

```php
public int $refreshInterval = 0;
...
$this->refreshInterval = $settings['dashboard_refresh'] ?? 0;
```

- Livewire's block list is in `vendor/livewire/livewire/src/Features/SupportLifecycleHooks/SupportLifecycleHooks.php:100` — `mount`, `boot`, `booted`, `hydrate*`, `dehydrate*`, `updating*`, `updated*`, `rendering`, `rendered` are all rejected.
- Repo convention for component state and JS: Livewire components are **single-file** — the PHP class is an inline anonymous class at the top of the Blade view (`return new class extends Component { ... };`); page-local JS lives in an `@script`/Alpine `x-init` block. Follow the existing `x-init` style in this same file.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused test | `XDEBUG_MODE=off php artisan test tests/Feature/DashboardLivewireTest.php` | all pass |
| Full suite (slow, ~8 min serial) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0, no changes pending |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors; if baselined lines moved, regenerate per its note |

> Run `php artisan config:clear && php artisan route:clear` first if you did not use `composer test` — a stale `routes-v7.php` causes Livewire endpoint-hash 404s.

## Scope

**In scope**:
- `resources/views/livewire/dashboard.blade.php`
- `tests/Feature/DashboardLivewireTest.php` (or the existing dashboard test file if the name differs — find it with `grep -rl "dashboard" tests/Feature | head`)

**Out of scope** (do NOT touch):
- `resources/views/components/layouts/app.blade.php` — layout is unrelated.
- The settings page (`resources/views/livewire/settings/index.blade.php`) and its persisted keys — `dashboard_refresh` already exists and validates; do not rename it.
- Any cache namespace or TTL — the cache keys/TTLs stay exactly as they are.

## Git workflow

- Branch: `perf/dashboard-auto-refresh` (repo convention: `perf/<slug>`, `fix/<slug>`, `improve/<slug>`).
- Commit style matches `git log`: plain imperative subject, e.g. `perf: make the dashboard auto-refresh actually refresh (#001)`.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Extract the data loading from `mount()` into a reusable method

In `resources/views/livewire/dashboard.blade.php`, rename the querying portion of `mount()` to a public method, e.g. `loadStats()`, leaving `mount()` as:

```php
public function mount(): void
{
    $settings = auth()->user()->settings ?? [];
    $this->refreshInterval = $settings['dashboard_refresh'] ?? 0;

    $this->loadStats();
}
```

Move everything from `$accessibleIds = app(AccessService::class)->accessibleUnitIds();` onward into `public function loadStats(): void`. Keep the bodies **byte-identical** — same cache keys, same TTLs, same property assignments. Do not add caching or change queries in this step.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/DashboardLivewireTest.php` → all pass (behaviour unchanged).

### Step 2: Add a public refresh action

Add next to `loadStats()`:

```php
/**
 * Auto-refresh entry point (#001). `mount()` is a protected
 * Livewire lifecycle method and cannot be called from the client, so the
 * refresh interval targets this action instead.
 */
public function refreshStats(): void
{
    $this->loadStats();
}
```

**Verify**: `grep -n "public function refreshStats" resources/views/livewire/dashboard.blade.php` → one match.

### Step 3: Fix the timer — call the action and clear the handle

Replace the `:340` line with an Alpine block that stores the handle and clears it on teardown (Livewire dispatches `livewire:navigating`/`beforeunload`; Alpine's `destroy()` runs when the component element is removed):

```blade
<div
    x-data="{
        interval: {{ $refreshInterval * 1000 }},
        timer: null,
        init() {
            if (this.interval > 0) {
                this.timer = setInterval(() => $wire.refreshStats(), this.interval);
            }
        },
        destroy() {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
        },
    }"
>
```

Do not add a `wire:poll` — the interval is user-configured and must stay dynamic.

**Verify**: `grep -n 'refreshStats\|\$wire.mount()' resources/views/livewire/dashboard.blade.php` → shows `refreshStats`; **no** `$wire.mount()` remains.

### Step 4: Fix or add the regression test

Find the dashboard test file (`grep -rl "dashboard" tests/Feature | head`). Add a test that a component-instance call to `refreshStats()` re-runs the aggregation (assert against the **database**, not component status — e.g. create a ticket in scope, call `refreshStats()`, assert the rendered component reflects the new count). If the existing test file already covers attribute re-render, extend it instead of creating a new file.

**Verify**: `XDEBUG_MODE=off php artisan test <that file>` → all pass, including the new test.

## Test plan

- New tests in the dashboard Feature test file:
  1. `refreshStats()` updates a public stat after the backing data changes (create a row in scope → `->call('refreshStats')` → assert rendered value).
  2. The rendered page contains `refreshStats` and does **not** contain `$wire.mount()` (guards the exact regression; `assertDontSee('$wire.mount()')` on the rendered HTML).
- Structural pattern: model after the existing Livewire feature tests that use the shared trait `InteractsWithTestSetup` (`tests/Support/Concerns/InteractsWithTestSetup.php`) for user/unit seeding — do not re-implement seeding by hand.
- Refactoring note: any test that previously relied on `mount()` for its side effects must keep working, because `mount()` still calls `loadStats()`.

## Done criteria

- [ ] `grep -rn '\$wire\.mount()' resources/views/` returns no matches
- [ ] `grep -n 'public function refreshStats' resources/views/livewire/dashboard.blade.php` returns one match
- [ ] `XDEBUG_MODE=off php artisan test <dashboard test file>` exits 0, including the new tests
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] No files outside the in-scope list are modified (`git status --porcelain`)
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- The code at `dashboard.blade.php:340` or the `mount()` shape does not match the excerpts above (the file has drifted).
- `refreshStats()` cannot be modelled without changing cache keys or TTLs — STOP; that is out of scope and changes observable behaviour.
- The existing dashboard test file's name/assertions imply a different refresh mechanism already exists.
- A step's verification fails twice after a reasonable fix attempt.

## Maintenance notes

- If a future change moves dashboard loading into `#[Computed]` properties or `render()`, revisit this: a computed refresh would make `$wire.refreshStats()` unnecessary, but `$wire.mount()` must never be reintroduced.
- Reviewer should scrutinize: that the extracted `loadStats()` body is unchanged apart from being moved (diff should be a pure move), and that the interval still honours `dashboard_refresh = 0` (off).
- Deferred, out of scope: replacing polling with Livewire `wire:poll` or SSE — the setting is user-configurable so a dynamic interval stays.
