# Plan 003: Implement Risk Mapping Layer for Health Threat Visualization

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.

> **Drift check (run first)**: `git diff --stat 0f4ada2..HEAD -- resources/views/livewire/maps/map.blade.php resources/views/livewire/maps/ resources/views/livewire/layouts/ app/Http/Controllers/Api/`
> If any in-scope file changed since this plan was written, compare against live code; on mismatch, STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: 001-boundary-unit-integration.md
- **Category**: direction
- **Planned at**: commit `0f4ada2`, 2026-07-18

## Why this matters

Currently, the dashboard shows unit boundaries and (in Plan 002) personnel locations. Health administrators need to visualize disease outbreaks, environmental hazards, resource shortages, and other risk factors on the same map to make informed decisions about resource allocation, intervention timing, and public communication. Adding a configurable risk mapping layer transforms the dashboard from a passive viewer into an active decision-support system.

## Current state

- `resources/views/livewire/maps/map.blade.php` — Base Leaflet map with tile server from config; exposes `window.map` (line 48).
- `resources/views/livewire/maps/unit.blade.php` — Shows unit boundaries as togglable GeoJSON layers (lines 65–105).
- No dedicated risk layer component or API endpoints exist.
- Conventions: Leaflet map instance stored on `window.map`; data passed via `@props` and `wire:` directives; maryUI components for UI; Persian labels; Tailwind/DaisyUI styling.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test | `php artisan test` | all pass |
| Lint | `php artisan pint --test` | exit 0 |
| Routes | `php artisan route:list` | lists risk-data routes |

## Scope

**In scope**:
- `resources/views/livewire/maps/risk-layer.blade.php` (new)
- `resources/views/livewire/maps/map.blade.php`
- `routes/api.php`
- `app/Http/Controllers/Api/RiskController.php` (new)
- `database/migrations/` — add risk_layers table if needed
- `resources/lang/fa/*.php` — add Persian translations

**Out of scope**:
- External risk data feeds (assume CSV/JSON upload or manual entry for now).
- Complex risk modeling algorithms — focus on visualization and basic querying.
- Real-time risk data streaming (use polling-based approach).

## Git workflow

- Branch: `advisor/003-risk-mapping-layer`
- Commit style: conventional commits (`feat:`, `fix:`). Match existing log.
- Do NOT push or open a PR.

## Steps

### Step 1: Create risk layer database table

Create migration for a `risk_layers` table to store risk layer definitions:
```php
Schema::create('risk_layers', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('type')->comment('heatmap, choropleth, point-density, etc.');
    $table->jsonb('config')->nullable()->comment('Layer-specific settings: colors, radius, weight, etc.');
    $table->jsonb('data')->nullable()->comment('GeoJSON or CSV data for the layer');
    $table->boolean('is_active')->default(false);
    $table->timestamps();
});
```

**Verify**: `php artisan migrate --pretend` shows the table creation SQL.

### Step 2: Create RiskController API endpoints

Create `app/Http/Controllers/Api/RiskController.php` with standard REST endpoints:
- `index()` — list all risk layers
- `store()` — create new risk layer (accepts GeoJSON/upload)
- `show($id)` — get single risk layer
- `update($id)` — modify risk layer
- `destroy($id)` — delete risk layer
- `data($id)` — return just the GeoJSON/data for map consumption

Add routes in `routes/api.php` under `auth:sanctum` middleware:
```php
Api::controller(RiskController::class)->group(function () {
    Route::apiResource('risk-layers', 'RiskController')->except(['create', 'edit']);
    Route::get('/risk-layers/{id}/data', [RiskController::class, 'data']);
});
```

**Verify**: `php artisan route:list | grep risk` shows the expected routes.

### Step 3: Create risk layer management UI

Create Livewire component `resources/views/livewire/maps/risk-layer.blade.php` that:
- Lists existing risk layers in a table
- Provides form to create/edit risk layer (name, description, type, config, file upload)
- Uses existing maryUI/DaisyUI form patterns from `resources/views/livewire/settings/`
- Includes file upload for GeoJSON/CSV with validation
- Toggles `is_active` to show/hide layer on map

**Verify**: `grep -n 'risk-layer\|RiskLayer' resources/views/livewire/maps/risk-layer.blade.php` returns matches.

### Step 4: Add risk layer controls to main map view

Modify `resources/views/livewire/maps/map.blade.php` to:
- Accept a `$riskLayers` prop (array of active risk layers)
- For each active layer, fetch its data via `/api/risk-layers/{id}/data`
- Render the layer on the Leaflet map using appropriate visualization:
  - Heatmap: use Leaflet.heat plugin with intensity weighting
  - Choropleth: use Leaflet GeoJSON with fillColor based on property
  - Point density: use Leaflet.circleMarker or heatmap
- Add layer toggle checkboxes in the map sidebar (similar to unit toggles in `maps/unit.blade.php`)
- Use distinct styling so risk layers don't conflict with unit boundaries or location heatmaps

**Verify**: `grep -n 'riskLayers\|risk.*layer' resources/views/livewire/maps/map.blade.php` returns matches.

### Step 5: Add risk layer type implementations

In the same `map.blade.php` file or a dedicated JS helper, implement rendering logic for each type:
- **heatmap**: Accept weight/intensity property, use `L.heatLayer()`
- **choropleth**: Accept value property, use `L.geoJSON()` with style function
- **point-density**: Accept point locations, use clustering or heatmap
- **buffer**: Accept distance, use Turf.js or Leaflet geometry utilities
- Allow admin to configure colors, radius, opacity per layer via the `config` JSON field

**Verify**: `grep -n 'heatmap\|choropleth\|point-density' resources/views/livewire/maps/map.blade.php` returns matches.

### Step 6: Add risk layer creation wizard

Enhance the risk layer UI (`risk-layer.blade.php`) with a step-by-step wizard:
1. Layer basics (name, description, type)
2. Data source (upload file, draw on map, enter coordinates)
3. Configuration (colors, weights, filters based on type)
4. Preview and save

Use existing Livewire wizards as reference (e.g., `resources/views/livewire/units/create.blade.php` if exists).

**Verify**: `grep -n 'step\|wizard' resources/views/livewire/maps/risk-layer.blade.php` returns matches.

### Step 7: Add risk layer to unit context menu

In `resources/views/livewire/maps/unit.blade.php`, add a "Show risk layers" button that opens a modal listing available risk layers with checkboxes to toggle them on/off for the selected unit's boundary area.

**Verify**: `grep -n 'risk.*layer\|RiskLayer' resources/views/livewire/maps/unit.blade.php` returns matches.

## Test plan

- `tests/Feature/RiskLayerTest.php` (create):
  - `it_can_create_and_list_risk_layers`
  - `it_serves_geojson_data_for_map_consumption`
  - `it_applies_correct_styling_based_on_type`
- `tests/Unit/RiskLayerTest.php`:
  - `it_has_expected_fields_and_casts`
  - `it_validates_config_json`
- Pattern: model after existing Pest feature tests in `tests/Feature/`.
- Verification: `php artisan test --filter=RiskLayer` → all pass.

## Done criteria

- [ ] `php artisan test --filter=RiskLayer` exits 0
- [ ] `php artisan pint --test` exits 0
- [ ] `php artisan route:list | grep risk` shows index, store, show, update, destroy, data routes
- [ ] `risk_layers` table exists with expected columns
- [ ] `resources/views/livewire/maps/risk-layer.blade.php` exists and loads
- [ ] Map displays at least one risk layer type correctly (e.g., heatmap)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:
- Database migration fails due to unsupported column types (e.g., jsonb on older MySQL).
- Leaflet or required plugins (Leaflet.heat) cannot be loaded.
- `AccessService` or `Unit::descendantIds()` signature changed significantly.
- `php artisan test` fails on unrelated tests before starting (infra blocker).

## Maintenance notes

- If risk layer datasets grow large (>10k features), consider server-side clustering or simplification.
- Leaflet plugin updates may require changes to rendering logic; keep CDN versions pinned or vendor critical plugins.
- Future enhancement: allow risk layers to reference SQL queries for live data (e.g., "show active tuberculosis cases").
- Consider adding risk layer expiration and automatic deactivation for time-bound threats.