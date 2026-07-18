# Plan 001: Integrate Boundary Drawing with Unit Management

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.

> **Drift check (run first)**: `git diff --stat 0f4ada2..HEAD -- app/Http/Controllers/Api/UnitController.php resources/views/livewire/units/ resources/views/livewire/maps/polygon.blade.php resources/views/livewire/maps/draw.blade.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: direction
- **Planned at**: commit `0f4ada2`, 2026-07-18

## Why this matters

The dashboard has two disconnected GIS capabilities:
1. `resources/views/livewire/maps/draw.blade.php` and `polygon.blade.php` let admins draw and save boundaries to the `boundaries` table.
2. `Unit` model has a `boundary_id` column (lines 20, 53–56 of `Unit.php`) but there is no UI to *assign* a drawn boundary to a unit.

The result: admins can draw polygons but cannot attach them to organizational units. Closing this gap enables jurisdictional mapping, facility catchment visualization, and location-based reporting — core to a health dashboard.

## Current state

- `app/Models/Unit.php:53-56` — `boundary()` belongsTo already defined.
- `app/Models/Boundary.php` — stores MULTIPOLYGON; exposes `geojson` accessor (line 42–48).
- `resources/views/livewire/maps/polygon.blade.php:14-33` — `saveBoundary()` inserts into `boundaries` table and dispatches `boundarySaved` event with `boundaryId`, but never links to a unit.
- `resources/views/livewire/units/index.blade.php` — unit CRUD list; no GIS surface.
- `resources/views/livewire/units/tree-item.blade.php` — tree node; no boundary indicator.
- `routes/api.php` — no boundary endpoints under `/unit`.
- Conventions: Anonymous Livewire components (`return new class extends Component`), `wire:click` / `wire:model`, maryUI components, Persian labels, conventional commits (`feat:`, `fix:`, `refactor:`).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test | `php artisan test` | all pass |
| Lint | `php artisan pint --test` | exit 0 |
| Routes | `php artisan route:list` | lists boundary routes |

## Scope

**In scope**:
- `resources/views/livewire/maps/polygon.blade.php`
- `resources/views/livewire/units/index.blade.php`
- `resources/views/livewire/units/tree-item.blade.php`
- `resources/views/livewire/maps/unit.blade.php`
- `routes/api.php`
- `app/Http/Controllers/Api/UnitController.php`

**Out of scope**:
- `resources/views/livewire/maps/draw.blade.php` — freeform drawing playground; leave as-is.
- `resources/views/livewire/maps/location.blade.php` — personnel tracking (separate).
- `app/Http/Controllers/Api/LocationController.php`
- Auth middleware or public API response shapes.

## Git workflow

- Branch: `advisor/001-boundary-unit-integration`
- Commit style: conventional commits (e.g. `feat: link drawn boundary to unit`). Match existing log.
- Do NOT push or open a PR.

## Steps

### Step 1: Accept `unit_id` in polygon saveBoundary

Modify `saveBoundary()` in `resources/views/livewire/maps/polygon.blade.php` to accept an optional `$unitId` parameter. After inserting the boundary, if a unit ID is provided, update the unit's `boundary_id`:

```php
// After line 28 (insertGetId succeeds):
if ($unitId) {
    \App\Models\Unit::find($unitId)?->update(['boundary_id' => $boundaryId]);
}
```

Add a public property `$unitId` and wire the parent to pass it.

**Verify**: `grep -n 'unit_id\|unitId' resources/views/livewire/maps/polygon.blade.php` returns matches.

### Step 2: Add "Edit boundary" action to unit index

In `resources/views/livewire/units/index.blade.php`, add an edit-boundary button (icon `o-map`) per unit row that opens a modal containing `<livewire:maps.polygon :unit-id="$unit->id">`. The polygon component should pre-load the unit's existing boundary GeoJSON if one exists.

Follow the existing pattern for edit/delete buttons in the same file.

**Verify**: `grep -n 'boundary\|Edit.*oundary\|o-map' resources/views/livewire/units/index.blade.php` returns new lines.

### Step 3: Pre-load existing boundary in polygon component

In `polygon.blade.php` `mount()`, if `$unitId` is set, fetch the unit and its boundary. Convert the boundary GeoJSON string to the wire model so it renders on the map. Use `Boundary::find($unit->boundary_id)?->geojson`.

**Verify**: `grep -n 'mount' resources/views/livewire/maps/polygon.blade.php` shows boundary loading logic.

### Step 4: Show boundary status badge in unit tree

In `resources/views/livewire/units/tree-item.blade.php`, add a small badge (e.g. `<x-icon name="o-map" class="text-green-500"/>`) when the unit has a non-null `boundary_id`. Match existing icon usage in the tree.

**Verify**: `grep -n 'boundary_id\|o-map' resources/views/livewire/units/tree-item.blade.php` returns matches.

### Step 5: Add API endpoints for unit boundary

In `routes/api.php` under the `auth:sanctum` group, add:
```php
Route::put('/unit/{unit}/boundary', [UnitController::class, 'assignBoundary']);
Route::delete('/unit/{unit}/boundary', [UnitController::class, 'removeBoundary']);
```

In `UnitController`, implement:
- `assignBoundary($unit)` — expects JSON `{ boundary_id }`, validates, updates unit.
- `removeBoundary($unit)` — sets `boundary_id` to null.

**Verify**: `php artisan route:list | grep boundary` lists both routes.

### Step 6: Enhance unit map view to show all unit boundaries at once

In `resources/views/livewire/maps/unit.blade.php`, units are already listed with toggles. Add a "Show all mapped units" checkbox that enables every unit with a non-null boundary at once. Use existing `toggleGeoJson` function.

**Verify**: `grep -n 'all.*mapped\|showAll' resources/views/livewire/maps/unit.blade.php` returns matches.

## Test plan

- `tests/Feature/UnitBoundaryTest.php` (create):
  - `it_assigns_boundary_to_unit_via_api`
  - `it_removes_boundary_from_unit_via_api`
  - `it_stores_boundary_and_links_to_unit_from_polygon_component`
- Pattern: model after any existing Pest feature test in `tests/Feature/`.
- Verification: `php artisan test --filter=UnitBoundary` → all pass.

## Done criteria

- [ ] `php artisan test --filter=UnitBoundary` exits 0
- [ ] `php artisan pint --test` exits 0
- [ ] `php artisan route:list | grep boundary` shows PUT and DELETE routes
- [ ] `grep -rn 'boundary' resources/views/livewire/units/` shows additions in index and tree-item
- [ ] `polygon.blade.php` accepts `$unitId` and pre-loads existing boundary
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:
- `Unit` model no longer has a `boundary_id` column (schema drift).
- `polygon.blade.php` structure differs significantly from excerpts (component rewrite).
- `php artisan test` fails on unrelated tests before starting (infra blocker).
- The `boundaries` table schema changed and `ST_GeomFromGeoJSON` is no longer used.

## Maintenance notes

- When unit tree grows beyond ~500 nodes, boundary pre-loading in Step 3 should be lazy (load on demand, not in `mount`).
- Future Leaflet version upgrades may affect draw control behavior; keep `waitForMapAndDraw` polyfill.
- If a `UnitBoundary` model is later introduced for metadata (drawn by, drawn at, area), this plan's direct FK approach is the correct foundation to extend.