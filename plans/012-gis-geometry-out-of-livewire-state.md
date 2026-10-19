# perf: map pages carry boundary geometry in public Livewire state twice

> **Plan 012** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1148 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/maps/map.blade.php resources/views/livewire/maps/unit.blade.php resources/views/livewire/maps/county.blade.php resources/views/livewire/maps/point.blade.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

Map components keep boundary geometry in **public** Livewire properties and then embed the same geometry a second time into the page via `Js::from()`. The dev database holds ~414 KB of GeoJSON across 551 boundaries, so each Livewire update on a map page re-serializes that geometry in the snapshot *and* re-emits it in the rendered script — for data that never changes within a request. Geometry belongs to the map layer (Alpine/module scope), not component state; keeping only IDs/filters in public state removes the double cost.

## Current state

- `resources/views/livewire/maps/unit.blade.php:14` — `public $units = [];`, filled at `:191-207`:

```php
$this->units = $units->map(fn($u) => [
    'id' => $u->id, 'name' => $u->name, 'type_id' => $u->unit_type_id, 'geojson' => $u->geojson,
])->toArray();
$this->dispatch('units-updated', units: $this->units);
```

- `:326` — the same data embedded again: `var allUnits = {{ Js::from($units) }};`
- `resources/views/livewire/maps/county.blade.php:12` — `public $regions = [];` filled at `:20-40`, then `Js::from($regions)` at `:88`.
- `resources/views/livewire/maps/point.blade.php:16` — same public-array pattern.
- **Load-bearing map lifecycle** (do not break it): the Leaflet instance is owned by `Alpine.store('map')` (`resources/js/map-store.js`, registered in `bootstrap.js`). `resources/views/livewire/maps/map.blade.php` is the ONLY place allowed to call `L.map()`. Host pages attach via `Alpine.store('map').onReady(cb)` and must never create a map. `x-data` must not be on a Livewire component root. See `docs/agents/maps.md`.
- Repo rule: never put a Leaflet instance (or map layers) in Alpine reactive state — keep them in closure locals/module scope.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/MapDashboardTest.php tests/Feature/GisTest.php` (find: `ls tests/Feature \| grep -i map`) | all pass |
| E2E map specs | `bash scripts/e2e-test.sh tests/e2e/maps` (slow; requires `.env.e2e`) | specs pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

> Playwright note: never `await networkidle` on map pages (Leaflet keeps fetching tiles); wait for `#unitMap`/`.leaflet-draw-*` instead.

## Scope

**In scope**:
- `resources/views/livewire/maps/unit.blade.php`
- `resources/views/livewire/maps/county.blade.php`
- `resources/views/livewire/maps/point.blade.php`
- The map tests

**Out of scope** (do NOT touch):
- `resources/views/livewire/maps/map.blade.php` and `resources/js/map-store.js` — the shared lifecycle is settled (issue #028); you may *call* `onReady`, not change it.
- `resources/views/livewire/maps/route.blade.php` / `route2.blade.php` unless they show the same public-geometry pattern (check first; if they do, handle them in the same consistent way, but do not change their routing logic).
- `units/map.blade.php` (`/units/{id}/map`) — deliberately separate (`window._drawnItems`, its own instance). Leave it.
- Any DB query or GIS endpoint.

## Git workflow

- Branch: `perf/gis-geometry-out-of-state`
- Commit style: `perf: keep boundary geometry out of public Livewire state (#012)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Decide the transport for each page

For each in-scope page, keep the geometry **in one place**: pass it to the map layer once from a non-public source. The cleanest fit with the existing lifecycle is to keep the public property as **IDs only** and have the page push geometry into the map store from an `@script` block or `x-init` that reads a `@json`/`Js::from` emitted once (not twice).

Concretely for `maps/unit`: make `public array $unitIds = []` (plus any scalar filters) the public state; keep the geometry list in a **private/closure value** and emit it once into the page. Remove the `Js::from($units)` duplicate by feeding the map from the single emitted payload.

**Verify**: `grep -n "public \$units" resources/views/livewire/maps/unit.blade.php` → no match; `grep -c "Js::from(\$units)\|Js::from(\$regions)" …` → follows the chosen single-emission design (state the count in the PR).

### Step 2: Keep the map lifecycle contract

Whatever you change, the page must still:
- attach through `Alpine.store('map').onReady(...)`, and
- never call `L.map()` itself, and
- keep map layers out of Alpine reactive state.

If your change makes the geometry arrive after first paint, `onReady` already queues the callback — do not introduce a "wait for a map to exist" loop.

**Verify**: `grep -n "onReady" resources/views/livewire/maps/unit.blade.php` still present; `grep -n "L.map(" resources/views/livewire/maps/unit.blade.php` → no match.

### Step 3: Prove the payload shrank and the map still draws

- Add/keep a test asserting the public snapshot for the page holds no `geojson` string (e.g. `! str_contains(json_encode($component->snapshot()), 'MultiPolygon')`).
- Run the map E2E specs (`tests/e2e/maps`) to prove markers/geometry still render after a sidebar navigation (the #028 regression class).

**Verify**: focused test passes; `bash scripts/e2e-test.sh tests/e2e/maps` → specs pass.

## Test plan

- New test: after rendering with a seeded boundary, the Livewire snapshot contains IDs but no GeoJSON string.
- Keep/repair the existing E2E map specs — they are the real guard that geometry still reaches Leaflet (they assert marker counts after `wire:navigate`).
- Structural pattern: existing map feature tests; E2E under `tests/e2e/maps/`.
- Verification: focused PHP test + `bash scripts/e2e-test.sh tests/e2e/maps`.

## Done criteria

- [ ] No in-scope page keeps boundary coordinates in a public Livewire property (snapshot test proves it)
- [ ] Geometry is emitted to the page exactly once per page (state the count in the PR)
- [ ] `grep -n "L.map(" resources/views/livewire/maps/{unit,county,point}.blade.php` → no matches
- [ ] `onReady` still used; no "wait for map" loop introduced
- [ ] Focused PHP map tests pass; `tests/e2e/maps` pass
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- A page's geometry is consumed by something other than the map (e.g. a table or export) — report, since it must stay reachable.
- The change would require putting a Leaflet layer/instance into Alpine reactive state to work — that is forbidden (#769); stop and report.
- Map E2E specs fail after the change and the cause is not a simple timing adjustment.

## Maintenance notes

- New map pages must follow the single-emission rule: public state = IDs/filters, geometry handed to the map store once.
- Reviewer should scrutinize: that geometry still arrives for the **first** paint (not only after an interaction), and that sidebar navigation still draws markers.
- Deferred: server-side geometry simplification/tiling for very large boundary sets.
