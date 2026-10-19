# perf: the todo calendar's first render is unbounded

> **Plan 006** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1142 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/todo/todo.blade.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The todo calendar's **first** render is unbounded. `$calendarStart`/`$calendarEnd` are `null` on mount, so the cache key falls back to `all` and `getEvents()` returns every accessible todo and every open ticket ever created — that full set is then embedded into the page as `@json($events)`. FullCalendar's `datesSet` handler immediately asks for a date-filtered range and discards the first set, so the expensive unbounded query *and* its payload are pure waste on every page load. Seeding the initial range and including it in the cache key makes the first render cost the same as the second.

## Current state

- `resources/views/livewire/todo/todo.blade.php:41-43` — private range, null until `datesSet`:

```php
private ?string $calendarStart = null;
private ?string $calendarEnd = null;
```

- `:71` — cache key includes the range with an unbounded fallback:

```php
$cacheKey = 'calendar:events:'.$user->id.':'.$calendarVersion.':'.md5(implode(',', $accessibleIds)).':'.($this->calendarStart ?? 'all').':'.($this->calendarEnd ?? 'all');
return Cache::remember($cacheKey, 120, function () use ($accessibleIds) { … });
```

- `:99-107` — the date filters are applied **only when the range is set**:

```php
if ($this->calendarStart) { $todoQuery->where('start_at', '>=', $this->calendarStart); $ticketQuery->where('created_at', '>=', $this->calendarStart); }
if ($this->calendarEnd)   { $todoQuery->where('start_at', '<=', $this->calendarEnd);   $ticketQuery->where('created_at', '<=', $this->calendarEnd); }
```

- `:426-431` — the always-on entry point:

```php
public function with(): array
{
    return ['events' => $this->getEvents()];
}
```

- `:716-717` — the result is inlined into the page and `datesSet` refetches:

```blade
events: @json($events),
datesSet: function(info) { … },
```

- `:48-58` — `fetchEvents(string $start, string $end)` sets the range and dispatches `calendar-updated`.
- Note the security contract at `:24-30` (#892/#975/#978): `fetchEvents()` must authorize `calendar` **first**; if you add another entry point that resolves events, it must do the same. Do not remove that check.
- Repo convention: single-file Livewire; private state never enters the snapshot, which is why the range is private today.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/TodoCalendarTest.php` (find it: `grep -rl "calendar" tests/Feature \| grep -i todo`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `resources/views/livewire/todo/todo.blade.php`
- The todo-calendar test file

**Out of scope** (do NOT touch):
- The null-unit todo ownership contract in the query (`:80-81`, `:91-92`, #838) — do not "simplify" the `whereIn`/`orWhere` grouping.
- The `calendar` authorization check in `fetchEvents()`.
- The `calendar_version` cache-invalidation mechanism or the 120s TTL.
- FullCalendar configuration beyond the initial view/`datesSet` interaction.

## Git workflow

- Branch: `perf/todo-calendar-bounded-first-render`
- Commit style: `perf: bound the todo calendar's first render to the visible range (#006)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Derive a default range in `mount()`

Seed the range from the calendar's default view before the first `getEvents()`. A month-ish window with padding avoids an immediate refetch:

```php
public function mount(): void
{
    $this->unit_id = session('current_unit_id', auth()->user()->person?->u_id);

    // #006: the first render used to be unbounded (`all`), then
    // FullCalendar's `datesSet` immediately refetched a range and discarded it.
    $this->calendarStart = now()->startOfMonth()->subWeek()->startOfDay()->toDateTimeString();
    $this->calendarEnd = now()->endOfMonth()->addWeek()->endOfDay()->toDateTimeString();
}
```

Match the format FullCalendar sends (`fetchEvents(string $start, string $end)` receives ISO strings) so both paths produce the same cache key shape.

**Verify**: `php artisan tinker` render the page or run the focused test; the cache key no longer contains `:all:`.

### Step 2: Skip the redundant `datesSet` refetch

In the Blade `datesSet` handler, compare the requested range to the seeded one and only call `$wire.fetchEvents(...)` when it differs (the initial `datesSet` fires on load with the same month). Keep the handler for genuine navigation.

**Verify**: manual smoke test — load the page, confirm the network tab shows **no** follow-up `/livewire/update` for the initial range, and navigating a month still refetches.

### Step 3: Prove the initial payload is bounded

Add a test that seeds both an old out-of-window todo and an in-window one, renders the component, and asserts the out-of-window one is absent from the initial `events` while the in-window one is present.

**Verify**: `XDEBUG_MODE=off php artisan test <calendar test>` → all pass.

## Test plan

- New tests in the todo-calendar test file:
  1. Initial render excludes a todo dated outside the default window (assert on the rendered/`events` payload, and that the seeded row exists in the DB).
  2. Initial render includes a todo dated inside the window.
  3. `fetchEvents('…','…')` still returns exactly the requested window (existing behaviour, keep/repair coverage).
  4. The `calendar` permission check still fires on `fetchEvents()` (existing test if present; do not delete it).
- Structural pattern: existing todo Livewire tests using `InteractsWithTestSetup`; note the repo rule that assertions go against the database or rendered output, never component status alone.

## Done criteria

- [ ] A rendered first paint's `events` payload contains no todo/ticket outside the default window (new test)
- [ ] The initial page does not issue a second `fetchEvents` round trip for the same range (verified manually; state it in the PR)
- [ ] Cache key no longer contains `:all:` on a normal load
- [ ] The `calendar` authorization check in `fetchEvents()` is untouched (`git diff` shows no change there)
- [ ] `XDEBUG_MODE=off php artisan test <calendar test>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- The calendar's default view is not a month (confirm by reading the FullCalendar config) — pick the range that matches the real default view instead of assuming a month, or report the mismatch.
- The `datesSet` handler must run on first paint for some other reason (e.g. it sets state the view needs) — report it rather than skipping it.
- Making the range non-null changes any existing test's expected event set — that is a semantic change; report before adapting the test.

## Maintenance notes

- If a future change adds a week/day view, the seeded range should follow the view, not stay month-based.
- Reviewer should scrutinize: that the first paint is not merely *smaller* but *correct* (includes the visible month's events), and that month navigation still works.
- Deferred: server-side event windowing per zoom level — not needed while the default view is fixed.
