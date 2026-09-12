# Plan 004: Fix N+1 and scope leaks in monitoring, dashboard, reports

> **Executor instructions**: Follow step by step. Run every verification command and confirm the expected result before continuing. If a STOP condition occurs, stop and report.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `bc1e38e`, 2026-09-12

## Why this matters
Three small, low-risk perf/data-integrity wins bundled together because they are each a one-line
(or few-line) change:

1. `tickets/⚡monitoring.blade.php` lazy-loads `user.person` per row (N+1).
2. `hr/dashboard.blade.php` computes vacancies outside its `Cache::remember` block.
3. `reports/index.blade.php` mounts `Unit::all()`, loading the whole units table and leaking
   out-of-scope unit names to every report viewer.

## Current state
- `resources/views/livewire/tickets/⚡monitoring.blade.php:69` loads
  `with(['user:id,n_code','unit:id,name'])`, renders `user->person` at `:224`.
- `resources/views/livewire/hr/dashboard.blade.php:77-84` runs `Unit::whereIn(...)->withCount('person')`
  and filters in PHP, outside the `Cache::remember` at `:34`.
- `resources/views/livewire/reports/index.blade.php:27` `$this->units = Unit::all();`; the scoped
  `getUnitsProperty()` (`:111-115`) exists but is unused by the view.

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Monitoring test | `XDEBUG_MODE=off php artisan test tests/Feature/` (ticket/monitoring files) | pass |
| HR dashboard test | `XDEBUG_MODE=off php artisan test tests/Feature/` (hr dashboard) | pass |
| Format | `vendor/bin/pint --dirty` | clean |

## Scope
**In scope**: the three blade files above.
**Out of scope**: Api controllers, models, migrations.

## Git workflow
- Branch off `rebecca`; commit `perf: fix N+1 and scope leaks in livewire views`.

## Steps

### Step 1: Eager-load user.person in monitoring
Change the `with()` to include `user.person` (e.g. `with(['user.person', 'unit:id,name'])`).
**Verify**: view renders without error; no new query count regression (if query log available).

### Step 2: Fold vacancies into cache and compute in SQL
Move the vacancy computation inside a cached closure (or give it its own `Cache::remember`), and
prefer a SQL `whereDoesntHave` over the in-PHP `filter`.
**Verify**: `hr/dashboard` still renders correct vacancy counts.

### Step 3: Use the scoped units in reports index
Replace `Unit::all()` with `$this->getUnitsProperty()` for the unit selector.
**Verify**: report viewer only sees units they can access.

### Step 4: Format + test
`vendor/bin/pint --dirty`; run the touched features' tests.
**Verify**: green, clean.

## Test plan
- Add/adjust a Livewire test asserting the reports index does NOT expose out-of-scope units
  (if one doesn't already exist).

## Done criteria
- monitoring eager-loads `user.person`.
- vacancies cached.
- reports index uses scoped units.
- Tests green; Pint clean.

## STOP conditions
- `getUnitsProperty()` returns a different shape than the selector expects (e.g. needs an `all`
  collection) — adapt carefully, don't silently change the dropdown.
- A test already asserts `Unit::all()` behavior intentionally.