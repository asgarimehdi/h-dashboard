# Plan 014: Consolidate maps/route versions

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: 2026-09-19

## Why this matters
Two separate route planning components exist (`maps/route` and `maps/route2`) with overlapping functionality. This confuses users and doubles maintenance.

## Current state
- `resources/views/livewire/maps/route.blade.php` (110 lines): accepts geocoded place name strings
- `resources/views/livewire/maps/route2.blade.php` (197 lines): accepts hardcoded waypoint lat/lng pairs
- Both use the same OSM routing service
- `routes/web.php` lines 87-88: both registered

## Steps

### Step 1: Analyze both components
Read both files to understand the full feature set and identify which capabilities to merge.

### Step 2: Create unified component
Merge into `resources/views/livewire/maps/route.blade.php` with an input mode toggle:
- Mode 1: Geocoded place names (from route.blade.php)
- Mode 2: Coordinate input (from route2.blade.php)

### Step 3: Update routes
Keep only one route in web.php. Remove the duplicate.

### Step 4: Delete old file
Remove `resources/views/livewire/maps/route2.blade.php`.

### Step 5: Verify
**Verify**: `cd /home/runner/h-dashboard && ls resources/views/livewire/maps/route*.blade.php` → only route.blade.php
**Verify**: `grep -c "maps/route" routes/web.php` → should be 1 (not 2)

## Done criteria
- [ ] Single unified route planning component
- [ ] Both input modes (place names + coordinates) supported
- [ ] Old route2 deleted
- [ ] pint passes
