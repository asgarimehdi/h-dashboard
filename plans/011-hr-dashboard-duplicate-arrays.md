# perf: the HR dashboard serializes its aggregates twice per render

> **Plan 011** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P3` · formerly GitHub issue #1147 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/hr/dashboard.blade.php`

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The HR dashboard assigns the same five grouped datasets **twice** — once into `$this->stats` and again into five public `by_*` arrays — and the view only reads three scalars (`total`, `active`, `retired`) from `$stats`. Every render therefore serializes the five grouped arrays twice into the Livewire snapshot and update payload. Removing the duplicate keeps the five arrays as the single source and the three scalars as plain values.

## Current state

- `resources/views/livewire/hr/dashboard.blade.php:69-74`:

```php
$this->stats = $data;
$this->byUnit = $data['by_unit'];
$this->bySemat = $data['by_semat'];
$this->byTahsil = $data['by_tahsil'];
$this->byEstekhdam = $data['by_estekhdam'];
$this->byRadif = $data['by_radif'];
```

- `:10` — `public array $stats = [];`
- `:120-122` — the only reads of `$stats` in the view:

```blade
<x-stat title="کل پرسنل" value="{{ $stats['total'] }}" … />
<x-stat title="فعال" value="{{ $stats['active'] }}" … />
<x-stat title="بازنشسته" value="{{ $stats['retired'] }}" … />
```

- Confirm no other `$stats` read exists: `grep -n '\$stats' resources/views/livewire/hr/dashboard.blade.php` should list only the assignment and these three.
- Repo conventions: single-file Livewire; public scalar props for view-only values are idiomatic here (the dashboard page does the same).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/HrDashboardTest.php` (find: `ls tests/Feature \| grep -i hr`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `resources/views/livewire/hr/dashboard.blade.php`
- The HR dashboard test file

**Out of scope** (do NOT touch):
- The query that builds `$data` (the cached aggregate) — only where its values are stored changes.
- The HR analytics API controller or `HrStatsController`.
- The five `by_*` arrays and their shape — charts read them.

## Git workflow

- Branch: `perf/hr-dashboard-single-source`
- Commit style: `perf: keep one copy of the HR dashboard aggregates (#011)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Replace `$stats` with three scalars

Replace `public array $stats = [];` with three public ints (or keep them as explicit props alongside the five arrays):

```php
public int $totalPersons = 0;
public int $activePersons = 0;
public int $retiredPersons = 0;
```

At `:69-74`, assign from `$data` instead of `$this->stats = $data`:

```php
$this->totalPersons = (int) ($data['total'] ?? 0);
$this->activePersons = (int) ($data['active'] ?? 0);
$this->retiredPersons = (int) ($data['retired'] ?? 0);
$this->byUnit = $data['by_unit'];
…
```

Update the three `<x-stat>` reads at `:120-122` to the new props.

**Verify**: `XDEBUG_MODE=off php artisan test <hr test>` → all pass.

### Step 2: Pin the snapshot payload

Add a test asserting the component snapshot contains no `stats` key (and that the three scalars render the right values — assert against the seeded DB, not component status).

**Verify**: `XDEBUG_MODE=off php artisan test <hr test>` → all pass including the new test.

## Test plan

- New test: seed persons with known active/retired split for an in-scope unit; render; assert the three stat values appear and `$component->snapshot()` has no `stats` key.
- Structural pattern: existing HR dashboard tests using `InteractsWithTestSetup`.
- Verification: `XDEBUG_MODE=off php artisan test <hr test>` → all pass.

## Done criteria

- [ ] `grep -n "\$stats" resources/views/livewire/hr/dashboard.blade.php` returns no matches
- [ ] The five `by_*` arrays are assigned exactly once each
- [ ] New snapshot test passes
- [ ] `XDEBUG_MODE=off php artisan test <hr test>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- The view (or any child component) reads `$stats` somewhere else, including via `$this->stats` — report the read before removing it.
- Setting `$stats` has a side effect (e.g. a `#[Computed]` or a parent listener) — report it.

## Maintenance notes

- If a future stat is added, add a scalar prop; do not reintroduce a bundled `$stats` array that duplicates the `by_*` arrays.
- Reviewer should scrutinize: that the numbers rendered are identical after the change.
