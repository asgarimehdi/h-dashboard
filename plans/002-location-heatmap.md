# Plan 002: Add Personnel Location Heat Map and Real-Time Presence Layer

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.

> **Drift check (run first)**: `git diff --stat 0f4ada2..HEAD -- app/Models/LocationLog.php app/Http/Controllers/Api/LocationController.php resources/views/livewire/maps/location.blade.php resources/views/livewire/maps/map.blade.php`
> If any in-scope file changed since this plan was written, compare against live code; on mismatch, STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: 001-boundary-unit-integration.md
- **Category**: direction
- **Planned at**: commit `0f4ada2`, 2026-07-18

## Why this matters

The dashboard logs GPS points per user (`location_logs` table) and the mobile app pushes coordinates via `POST /api/location` (LocationController). The current location view (`maps/location.blade.php`) shows individual user tracks on demand with a hard `limit(20)` — useful for one-off investigations but not for operational situational awareness. Adding a heat map and real-time presence layer enables administrators to see staff distribution across health districts, identify coverage gaps, and monitor field team movements at a glance.

## Current state

- `app/Models/LocationLog.php` — simple model; `user_id`, `latitude`, `longitude`, `created_at`. No relationship to `User` defined (missing `belongsTo`).
- `app/Http/Controllers/Api/LocationController.php` — `store()` works (lines 17–36); `index()`, `show()`, `update()`, `destroy()` return 501 stubs.
- `resources/views/livewire/maps/location.blade.php` — fetches logs per user with date range filter; `limit(20)` on line 62; renders markers + polyline on Leaflet map.
- `resources/views/livewire/maps/map.blade.php` — base Leaflet map; hardcoded tile server IP from config.
- Conventions: Anonymous Livewire components, Jalali datepickers for Persian dates, maryUI, Leaflet for maps.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test | `php artisan test` | all pass |
| Lint | `php artisan pint --test` | exit 0 |
| Routes | `php artisan route:list` | lists new location routes |

## Scope

**In scope**:
- `app/Models/LocationLog.php`
- `app/Http/Controllers/Api/LocationController.php`
- `resources/views/livewire/maps/location.blade.php`
- `resources/views/livewire/maps/map.blade.php`
- `routes/api.php`
- `database/migrations/` — add spatial index if needed

**Out of scope**:
- `resources/views/livewire/maps/unit.blade.php` — unit boundaries (covered in plan 001).
- Mobile app code — this plan covers server-side only.
- WebSocket/pusher real-time infrastructure — use polling-based approach.
- Auth middleware changes.

## Git workflow

- Branch: `advisor/002-location-heatmap`
- Commit style: conventional commits (`feat:`, `fix:`). Match existing log.
- Do NOT push or open a PR.

## Steps

### Step 1: Add User relationship to LocationLog model

In `app/Models/LocationLog.php`, add:
```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

Add the necessary import (`use Illuminate\Database\Eloquent\Relations\BelongsTo;`).

**Verify**: `grep -n 'function user' app/Models/LocationLog.php` returns a match.

### Step 2: Implement LocationController index for heatmap data

Replace the 501 stub in `LocationController::index()` with an endpoint that returns aggregated location data suitable for a heatmap:

```php
public function index(Request $request): JsonResponse
{
    $query = LocationLog::with('user.person');
    
    if ($request->filled('unit_id')) {
        $unitIds = \App\Models\Unit::descendantIds($request->unit_id);
        $userIds = \App\Models\User::join('user_units', 'users.id', '=', 'user_units.user_id')
            ->whereIn('user_units.unit_id', $unitIds)
            ->pluck('users.id');
        $query->whereIn('user_id', $userIds);
    }
    
    if ($request->filled('from')) {
        $query->where('created_at', '>=', $request->from);
    }
    
    if ($request->filled('to')) {
        $query->where('created_at', '<=', $request->to);
    }
    
    $logs = $query->latest()->limit(5000)->get(['user_id', 'latitude', 'longitude', 'created_at']);
    
    return response()->json($logs->map(fn($l) => [
        'lat' => $l->latitude,
        'lng' => $l->longitude,
        'userId' => $l->user_id,
        'userName' => $l->user?->person?->f_name ?? 'نامشخص',
        'time' => $l->created_at->toISOString(),
    ]));
}
```

**Verify**: `php artisan route:list | grep location` shows GET `/api/location`.

### Step 3: Add heatmap layer to location view

In `resources/views/livewire/maps/location.blade.php`, add a toggle button to switch between "marker view" (current behavior) and "heat map view". Use Leaflet.heat plugin (add via CDN in `@assets`):

```html
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
```

In `@script`, implement a `heatLayer` variable that accepts the location data array and renders as a heatmap. Add a wire:model boolean `$showHeatmap` to toggle between views. Remove the `limit(20)` constraint when heatmap mode is active.

**Verify**: `grep -n 'heat\|Heatmap\|showHeatmap' resources/views/livewire/maps/location.blade.php` returns matches.

### Step 4: Add real-time presence indicators

Add a "live presence" mode that shows only users who have logged a location in the last N minutes (configurable, default 30 min). Add a public property `$liveMinutes` (default 30) to the component. In `fetchLocationLogs()`, add a branch:

```php
if ($this->liveMode) {
    $query->where('created_at', '>=', now()->subMinutes($this->liveMinutes));
}
```

Render live presence markers with a distinct pulsing icon (use CSS animation or Leaflet divIcon with pulse effect).

**Verify**: `grep -n 'liveMode\|liveMinutes' resources/views/livewire/maps/location.blade.php` returns matches.

### Step 5: Add unit-scoped location filtering

Add a unit dropdown (reusing the existing tree picker pattern from `resources/views/livewire/partials/unit-tree-picker.blade.php`) to filter locations by organizational unit. Use `AccessService` to scope to the current user's accessible units.

**Verify**: `grep -n 'unit-tree-picker\|treePicker' resources/views/livewire/maps/location.blade.php` returns matches.

### Step 6: Add spatial index migration

Create a migration to add a spatial index on `location_logs(latitude, longitude)` for efficient bounding-box queries:

```bash
php artisan make:migration add_spatial_index_to_location_logs_table
```

In the migration:
```php
public function up(): void
{
    // MySQL spatial index
    DB::statement('CREATE SPATIAL INDEX idx_location_logs_coords ON location_logs(latitude, longitude)');
}

public function down(): void
{
    DB::statement('DROP INDEX idx_location_logs_coords ON location_logs');
}
```

**Verify**: `php artisan migrate` succeeds without errors.

### Step 7: Implement GET /api/location/{userId} for mobile app

In `LocationController::show()`, return the latest location for a specific user:

```php
public function show(string $id): JsonResponse
{
    $log = LocationLog::where('user_id', $id)->latest()->first();
    
    if (! $log) {
        return response()->json(['message' => 'No location found'], 404);
    }
    
    return response()->json([
        'latitude' => $log->latitude,
        'longitude' => $log->longitude,
        'logged_at' => $log->created_at->toISOString(),
    ]);
}
```

**Verify**: `php artisan route:list | grep 'api/location'` shows GET `/api/location/{id}`.

## Test plan

- `tests/Feature/LocationHeatmapTest.php` (create):
  - `it_returns_heatmap_data_for_all_users`
  - `it_scopes_heatmap_data_by_unit`
  - `it_filters_by_date_range`
  - `it_returns_latest_user_location`
- `tests/Unit/LocationLogTest.php` (create):
  - `it_has_user_relationship`
- Pattern: model after any existing Pest test in `tests/Feature/`.
- Verification: `php artisan test --filter=Location` → all pass.

## Done criteria

- [ ] `php artisan test --filter=Location` exits 0
- [ ] `php artisan pint --test` exits 0
- [ ] `LocationLog` model has `user()` relationship
- [ ] `LocationController::index()` returns heatmap-ready JSON (no 501)
- [ ] `LocationController::show()` returns latest user location (no 501)
- [ ] `maps/location.blade.php` has heatmap toggle and live presence mode
- [ ] Spatial index migration exists and applies cleanly
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:
- `location_logs` table schema differs from documented structure (columns missing).
- Database engine is not MySQL/PostgreSQL with spatial index support.
- `AccessService` or `Unit::descendantIds()` signature changed significantly.
- `php artisan test` fails before starting (infra blocker).

## Maintenance notes

- Heat map with 5000+ points may be slow in browser. If datasets grow, consider server-side grid aggregation (return counts per grid cell instead of raw points).
- The `limit(5000)` in Step 2 is a deliberate ceiling; remove only with pagination or streaming.
- Leaflet.heat CDN dependency should be vendored if offline operation is required.
- Real-time presence relies on mobile app pushing locations at sufficient frequency; stale data produces misleading "absent" indicators.