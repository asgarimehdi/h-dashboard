# Plan 008: Remove dead code and stub routes

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: 2026-09-19

## Why this matters
Dead code (glowingcard component, register view, index redirect, commented routes) creates confusion for contributors and increases maintenance surface.

## Current state
- `resources/views/livewire/glowingcard.blade.php`: 106 lines, empty class body, zero references
- `resources/views/livewire/auth/register.blade.php`: full component, route commented out in web.php line 18
- `resources/views/livewire/index.blade.php`: renders header then immediately redirects to /dashboard
- `routes/web.php` lines 49-54: commented-out welcome/dashboard stubs

## Steps

### Step 1: Delete glowingcard
```bash
rm resources/views/livewire/glowingcard.blade.php
```

### Step 2: Delete register view (route is intentionally disabled)
```bash
rm resources/views/livewire/auth/register.blade.php
```

### Step 3: Replace index Livewire redirect with route redirect
In `routes/web.php`, change:
```php
Route::livewire('/', 'index');
```
to:
```php
Route::redirect('/', '/dashboard');
```
Then delete `resources/views/livewire/index.blade.php`.

### Step 4: Remove commented route stubs
Delete lines 49-54 in web.php (commented welcome/dashboard closures).

### Step 5: Verify
**Verify**: `cd /home/runner/h-dashboard && ls resources/views/livewire/glowingcard.blade.php resources/views/livewire/auth/register.blade.php resources/views/livewire/index.blade.php 2>&1` → all "No such file"
**Verify**: `grep -n "Route::redirect.*dashboard" routes/web.php` → should show the new redirect

## Done criteria
- [ ] 3 dead view files removed
- [ ] Index route uses Route::redirect
- [ ] Commented stubs removed
- [ ] pint passes
