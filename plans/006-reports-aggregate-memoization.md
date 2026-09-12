# Plan 006: Memoize reports aggregation + stop org-chart N+1

> **Executor instructions**: Follow step by step. Run every verification command and confirm the expected result before continuing. If a STOP condition occurs, stop and report.

## Status
- **Priority**: P2
- **Effort**: M
- **Risk**: MEDIUM
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `bc1e38e`, 2026-09-12

## Why this matters
The reports screen re-runs ~10–20 GROUP BY / COUNT aggregate queries on **every** filter change,
twice (once for `reportData()` in render, once for `chartPayload()`), with a downstream JS
`$watch` on 7 filters firing per keystroke/select. Separately, the org-chart builds child nodes
with one query per parent/level, so a deep tree costs N queries per render.

## Current state
- `resources/views/livewire/reports/advanced.blade.php:86-184` aggregates; `:191` `chartPayload()`
  re-invokes `reportData`; `:435-441` `$watch` on filters.
- `resources/views/livewire/reports/index.blade.php:46-108` similar; `:122` chartPayload.
- `resources/views/livewire/hr/org-chart.blade.php:91,116,138` child queries in foreach; recursion `:98`.

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Reports test | `XDEBUG_MODE=off php artisan test tests/Feature/` (reports files) | pass |
| Org chart test | `XDEBUG_MODE=off php artisan test tests/Feature/` (org chart) | pass |
| Format | `vendor/bin/pint --dirty` | clean |

## Scope
**In scope**: the three blade files.
**Out of scope**: Api `ReportController`/`HrStatsController` (already cache), AccessService.

## Git workflow
- Branch off `rebecca`; commit `perf: memoize report aggregates + single-pass org-chart`.

## Steps

### Step 1: Compute reportData once, reuse for chart
Refactor so `reportData()` runs once per request and its result feeds both the render and the
chart payload (e.g. memoize into a computed property keyed by current filter state, or pass the
already-computed data into the JS instead of a second `chartPayload` round-trip). Optionally wrap
the aggregates in `Cache::remember` keyed by filters (mirroring Api `ReportController`).
**Verify**: instrument query count (or code review) confirms one evaluation per filter change.

### Step 2: Decouple chart from per-keystroke re-aggregation
If Livewire rerenders per keystroke, either debounce or move heavy aggregation out of the
per-keystroke path.
**Verify**: typing in a report filter does not fire 20+ queries per keystroke.

### Step 3: Load org-chart units in one pass
Replace the per-node `Unit::where('parent_id', $node->id)->get()` with a single query loading all
accessible units + `unitType`, grouped by `parent_id` in memory, and walk the tree from that map.
**Verify**: org-chart renders identically for a deep tree with far fewer queries.

### Step 4: Format + test
`vendor/bin/pint --dirty`; run reports + org-chart tests.
**Verify**: green.

## Test plan
- Existing reports/org-chart tests are the regression net (must stay green).
- Add a test asserting reports index still shows the same data with memoized code.

## Done criteria
- One aggregate evaluation per filter change (render + chart share it).
- Org-chart uses a single load + in-memory grouping.
- Reports + org-chart tests green; Pint clean.

## STOP conditions
- Memoization changes chart data in a way a test can't explain — stop and capture the exact
  difference rather than shipping a data drift.