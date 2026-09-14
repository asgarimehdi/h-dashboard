# Plan 007: Hot-Path Performance (O(n²), PHP aggregation, polling, GeoJSON)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- app/Http/Controllers/Api/OrgChartController.php resources/views/livewire/reports/index.blade.php resources/views/livewire/map/map-dashboard.blade.php resources/views/livewire/dashboard.blade.php resources/views/livewire/it/network-traffic-chart.blade.php resources/views/livewire/it/multi-gauge.blade.php app/Http/Controllers/Api/TrafficController.php app/Models/Boundary.php app/Http/Controllers/Api/GisController.php app/Http/Requests/UnitScopedRequest.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P2 (quadratic subtree, full-table PHP aggregation, self-HTTP per map pan, per-model PostGIS queries)
- **Effort**: M
- **Risk**: LOW (pure refactors, shapes unchanged; polling-interval raises need product nod — default to code-safe parts first)
- **Depends on**: 001
- **Category**: perf
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

Seven hot paths each waste an order of magnitude: subtree formatting is O(n²), reports aggregate in PHP over full row sets, map stats does intra-process HTTP to itself on every pan, dashboard re-resolves org scope ~7× per render, Zabbix charts poll every 10s, boundary GeoJSON costs one PostGIS query per model, and `in_array` scans scope arrays per row.

## Current state (all verified)

- `OrgChartController:127,130`: `$byId->filter(parent_id == ...)` inside recursive `$format`.
- `reports/index.blade.php:81-92`: `->with('unit')->get()->groupBy(...)`, 3× `groupBy` in PHP; `:27`: `Unit::all()` unscoped (scoped `getUnitsProperty()` at :111-114 exists).
- `map-dashboard.blade.php:116-119`: `Http::withToken(...)->get(route('api.gis.stats'))` in `loadStats()`, called from `onMapMoved` :56. (Session-token caching of `mapToken` itself is plan 008 — don't touch the token here.)
- `dashboard.blade.php:41,177,180,199,202,215,218`: `accessibleUnitIds()` in mount + 3 computed props; `:229`: `setInterval(() => { $wire.mount() })`.
- `network-traffic-chart.blade.php:106`: 10s `setInterval`; `multi-gauge.blade.php:51`: 30s per gauge; `TrafficController:25-27`: 30s TTL; `ZabbixService:24-38`: `output=extend` + PHP rate loop per poll.
- `Boundary.php:34,41-44`: `DB::selectOne(ST_AsGeoJSON...)` per access.
- `UnitScopedRequest:30`, `HardwareImport:170,300`, `TicketController:143`: linear `in_array`/`array_intersect` over scope arrays.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Tests | `XDEBUG_MODE=off php artisan test --filter='OrgChart|Report|Map|Gis|Dashboard|Traffic|Zabbix|HardwareImport'` | pass |
| Full | `composer test` | pass |

## Scope

**In scope**: files above + `GisController::stats` extraction target (service or trait — new file allowed).
**Out of scope**: export-filter JOIN rewrite? NO — that IS here (Step 2b). Token session caching (plan 008). Import queueing (plan 006). Scope-mechanism unification (plan 009).

## Steps

### Step 1: O(n²) → groupBy + SQL aggregation + scoped dropdown

OrgChart: `$grouped = $units->groupBy('parent_id')`, recurse via `$grouped->get($id, [])`. Reports: `selectRaw('unit_id, count(*)')->groupBy` in SQL + versioned `Cache::remember` on `reportData`; delete `:27` `Unit::all()`, use `getUnitsProperty()`.
**Verify**: same JSON/rows, faster; suites pass.

### Step 2: Kill self-HTTP + export JOINs

Extract `GisController::stats` query into a service; call directly from `loadStats()`. Export filters (`HardwareExportController:45,87,96,102` 4× correlated `whereHas`) → mirror `GisController::hardware()` :165-186 direct JOINs on persons/units/semats (preserve LIKE-escape semantics exactly).
**Verify**: map pan works without HTTP round-trip; export rows identical; suites pass.

### Step 3: Dashboard scope-once + poll discipline

Resolve `$accessibleIds` once in `mount`, pass into computed closures. Replace `$wire.mount()` interval with `wire:poll` on chart props only. Zabbix: raise gauge intervals toward 60s, request only needed fields (drop `output=extend`), share one `getLatestValues` per dashboard (normalize: sort + count-limit + hashed key — coordinate with plan 003's `md5` key).
**Verify**: single descendant-CTE per render; charts still live; suites pass.

### Step 4: GeoJSON + membership micro-opts

List queries: `selectRaw('ST_AsGeoJSON(boundary) as geojson_computed')`, accessor reads it first else per-id 60-min cache. Membership: `$set = array_flip($accessibleIds); isset($set[$id])` at the three sites.
**Verify**: boundary lists flat query count; import row throughput same-or-better; suites pass.

## Test plan

- Query-count assertions (subtree, thread, boundary list), row-equality on reports/exports, existing suites green.

## Done criteria

- [ ] No `filter()` inside recursive format; SQL aggregation on reports
- [ ] No intra-process HTTP in map stats; export JOINs mirror GIS
- [ ] Scope resolved once per dashboard render; polls targeted
- [ ] GeoJSON accessor cache-first; membership O(1)
- [ ] No out-of-scope changes

## STOP conditions

- Polling-interval raises contested (product wants 10s) → keep code-safe parts, note intervals as deferred.
- Export JOIN rewrite changes row semantics (duplicates from joins) → stop, report diff.
- Verification fails twice → stop, report.
