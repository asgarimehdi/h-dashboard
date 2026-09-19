# Plan 008: Remove dead code and stub routes

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
Dead code (glowingcard component, register view, index redirect, commented routes) creates confusion for contributors and increases maintenance surface.

## Current state (verified)
- `resources/views/livewire/glowingcard.blade.php`: 54 lines, empty class body (line 5-6: `new class extends Component { // };`), zero references in routes or views
- `resources/views/livewire/auth/register.blade.php`: full component, route is **not registered** in web.php (was commented out)
- `resources/views/livewire/index.blade.php`: 29 lines — mount() redirects to `/dashboard` (line 11), then renders empty header/card. Pure overhead.
- `routes/web.php` lines 49-54: commented-out welcome/dashboard stubs

## Scope
**In scope**: 3 dead view files, commented route stubs, index route optimization

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
In `routes/web.php`, change line 55:
```php
Route::livewire('/', 'index');
```
to:
```php
Route::redirect('/', '/dashboard');
```
Then delete `resources/views/livewire/index.blade.php`.

**Why**: The index component's only purpose is `return redirect('/dashboard')` in mount(). A `Route::redirect()` achieves the same without loading Livewire, rendering a view, and then redirecting.

### Step 4: Remove commented route stubs
Delete lines 49-54 in web.php (commented welcome/dashboard closures).

### Step 5: Verify
```bash
ls resources/views/livewire/glowingcard.blade.php resources/views/livewire/auth/register.blade.php resources/views/livewire/index.blade.php 2>&1
# → all "No such file"
grep -n "Route::redirect.*dashboard" routes/web.php
# → should show the new redirect
```

### Step 6: Test review
Check if any test references these views:
```bash
grep -rn "glowingcard\|auth.register\|livewire.*index" tests/ --include="*.php"
```
If tests reference these, update or remove them.

## Done criteria
- [ ] 3 dead view files removed
- [ ] Index route uses Route::redirect
- [ ] Commented stubs removed
- [ ] `composer test` passes
- [ ] `vendor/bin/pint --dirty --format agent` passes
