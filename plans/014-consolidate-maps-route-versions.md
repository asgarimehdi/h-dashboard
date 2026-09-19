# Plan 014: Consolidate maps/route versions

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
Two separate route planning components exist (`maps/route` and `maps/route2`) with overlapping functionality. Both use the same OSM routing service. This confuses users and doubles maintenance.

## Current state (verified)
- `resources/views/livewire/maps/route.blade.php`: accepts geocoded place name strings
- `resources/views/livewire/maps/route2.blade.php`: accepts hardcoded waypoint lat/lng pairs
- `routes/web.php` lines 87-88: both registered
  ```php
  Route::livewire('/maps/route', 'maps/route');
  Route::livewire('/maps/route2', 'maps/route2');
  ```

## AGENTS.md conventions
- Single-file Livewire components
- Route: `Route::livewire('/path', 'feature.name')`
- Reference by dot-name string

## Steps

### Step 1: Analyze both components
Read both files to understand the full feature set:
```bash
wc -l resources/views/livewire/maps/route.blade.php resources/views/livewire/maps/route2.blade.php
```

### Step 2: Create unified component
Merge into `resources/views/livewire/maps/route.blade.php` with an input mode toggle:
- Mode 1: Geocoded place names (from route.blade.php)
- Mode 2: Coordinate input (from route2.blade.php)

Use a `public string $inputMode = 'place';` property to switch between modes.

### Step 3: Update routes
Keep only one route in web.php:
```php
Route::livewire('/maps/route', 'maps/route');
```
Remove the `maps/route2` line.

### Step 4: Delete old file
```bash
rm resources/views/livewire/maps/route2.blade.php
```

### Step 5: Add test
Create `tests/Feature/MapsRouteLivewireTest.php` (Pest):
- test mount loads with default place mode
- test can switch to coordinate mode
- test route calculation works with place names
- test route calculation works with coordinates

### Step 6: Verify
```bash
ls resources/views/livewire/maps/route*.blade.php
# → only route.blade.php
grep -c "maps/route" routes/web.php
# → should be 1 (not 2)
composer test -- --filter=MapsRouteLivewireTest
# → passes
```

## Done criteria
- [ ] Single unified route planning component
- [ ] Both input modes (place names + coordinates) supported
- [ ] Old route2 deleted
- [ ] Pest test added and passing
- [ ] `vendor/bin/pint --dirty --format agent` passes
