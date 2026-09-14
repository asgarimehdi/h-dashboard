# Plan 002: Critical Web Security (IDOR, SQL, XSS, CSP)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- resources/views/livewire/units/map.blade.php resources/views/livewire/dashboard.blade.php resources/views/livewire/hardware/_form-edit.blade.php app/Http/Middleware/SecurityHeaders.php bootstrap/app.php config/livewire.php routes/web.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P1 (exploitable today: any `organization`-role user can overwrite any unit boundary)
- **Effort**: M
- **Risk**: MED (CSP can break UI if enforced too early — hence report-only first)
- **Depends on**: none (parallel with 001)
- **Category**: security
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

Four findings form one attack surface: missing per-unit authorization on the boundary editor, raw SQL interpolation fed by a session value, a JS-context breakout in a Livewire action, and no CSP second layer. Any one is fixable in minutes; together they mean a single sanitizer slip becomes full compromise.

## Current state

- `resources/views/livewire/units/map.blade.php:16` `mount(int $id)`, `:24` `loadUnit()` uses `Unit::with('boundary')->find($this->unitId)`, `:36` `saveBoundary()` uses `Unit::find($this->unitId)`, `:68` `deleteBoundary()` same. Route group `routes/web.php:63-66` requires only broad `organization` role. No `accessibleUnitIds()` check anywhere in the file (verified).
- `resources/views/livewire/dashboard.blade.php:57`: `$ids = implode(',', $accessibleIds);` then `DB::selectOne("... IN ({$ids})")` at lines 60-64, 67-73, 76-82, 85-90, 108-112. `AccessService.php:24-27` takes `session('current_unit_id')` without `(int)` cast.
- `resources/views/livewire/hardware/_form-edit.blade.php:11`: `wire:click="selectPerson('{{ $pr['n_code'] }}', '{{ $pr['name'] }}')"` — `{{ }}` escapes HTML, not JS string context.
- `app/Http/Middleware/SecurityHeaders.php:11-21` sets only `X-Frame-Options`, `nosniff`, `Referrer-Policy`, legacy `X-XSS-Protection`. No `Content-Security-Policy` string exists anywhere in repo. Middleware wired in `bootstrap/app.php:35-36`. Livewire CSP-safe mode config exists at `config/livewire.php:253-258` (unused).
- `{!! $comment->body_html !!}` renders at `ticket-comments.blade.php:232,268` — the asset CSP must allow Leaflet (`unpkg.com`), same-origin Highcharts, fonts.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Scope check | `grep -n 'accessibleUnitIds\|Accessible' resources/views/livewire/units/map.blade.php` | empty before fix, non-empty after |
| SQL check | `grep -n 'IN ({$ids})' resources/views/livewire/dashboard.blade.php` | hits before, empty after |
| Tests | `composer test` (or `XDEBUG_MODE=off php artisan test --filter='Dashboard|Units|Hardware'`) | pass |
| Headers | `curl -sI http://localhost:8000/dashboard` after `php artisan serve` (authed) | CSP header present |

## Scope

**In scope**: the 4 files above + `app/Services/AccessService.php` (cast only) + `bootstrap/app.php` (only if CSP needs nonce wiring — prefer static header, avoid touching).
**Out of scope**: ticket comments sanitizer (plan 005), API hardening (plan 003), scope-mechanism unification (plan 009 — do NOT refactor AccessService broadly here), E2E.

## Steps

### Step 1: IDOR — scope the boundary editor

In all four methods (`mount`/`loadUnit`/`saveBoundary`/`deleteBoundary`), replace `Unit::find($this->unitId)` with a scoped lookup:
```php
$ids = app(\App\Services\AccessService::class)->accessibleUnitIds();
$unit = Unit::with('boundary')->whereIn('id', $ids)->find($this->unitId);
if (! $unit) { $this->error('دسترسی ندارید.'); return; }
```
(`mount` can call `loadUnit` which does the check — keep one path, don't double-query.)
**Verify**: `grep -n 'accessibleUnitIds' resources/views/livewire/units/map.blade.php` → ≥3 hits. Existing unit/map tests pass.

### Step 2: SQL interpolation → intval + empty guard

At `dashboard.blade.php:57`, replace with:
```php
$accessibleIds = array_map('intval', $accessibleIds);
if (empty($accessibleIds)) { /* set zero stats, return early from mount */ }
$ids = implode(',', $accessibleIds);
```
And in `AccessService.php:24`, cast: `$currentUnitId = (int) session('current_unit_id');` (keep falsy behavior for null/0).
**Verify**: `grep -n 'IN ({$ids})'` still shows same lines (interpolation of guaranteed ints is acceptable here) BUT `intval` line exists above; add a Pest assertion that mount with empty scope renders zeros, not 500.

### Step 3: JS breakout — pass key only

Change `_form-edit.blade.php:11` to `wire:click="selectPerson('{{ $pr['n_code'] }}')"` and resolve the name server-side in `selectPerson()` (it already receives nCode — look up Person by n_code within accessible scope). Do NOT pass `name` through the wire action at all.
**Verify**: `grep -n "selectPerson('" resources/views/livewire/hardware/_form-edit.blade.php` → single-arg form; person-import with quote in name no longer breaks the row button (manual or new test).

### Step 4: CSP report-only + HSTS + Permissions-Policy

Add to `SecurityHeaders.php` (keep existing headers):
```php
$csp = implode('; ', ["default-src 'self'", "script-src 'self' 'unsafe-inline' https://unpkg.com", "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com", "img-src 'self' data: blob:", "font-src 'self' https://fonts.gstatic.com", "connect-src 'self'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'"]);
$response->headers->set('Content-Security-Policy-Report-Only', $csp);
$response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
```
Do NOT enforce (`Content-Security-Policy`) yet — one release of report-only first. HSTS only if `request->isSecure()`.
**Verify**: header present via curl; full `composer test` green; manual click-through of dashboard/hardware/tickets/maps shows no blocked rendering (report-only never blocks).

## Test plan

- New/updated Pest: scoped-boundary 403 test (user with `organization` role, out-of-scope unit id → error, boundary unchanged); dashboard empty-scope renders zeros; selectPerson single-arg test.
- Existing: Dashboard/Units/Hardware suites pass.

## Done criteria

- [ ] Boundary editor rejects out-of-scope unit in all 4 methods
- [ ] `intval` cast + empty-scope guard in dashboard; `(int)` cast on session unit
- [ ] No two-arg `selectPerson(` in Blade
- [ ] CSP report-only + Permissions-Policy headers live
- [ ] Tests green; no out-of-scope files modified

## STOP conditions

- `selectPerson()` server method doesn't exist / signature differs from excerpt → stop, report actual.
- CSP report-only breaks Livewire (shouldn't — report-only) → stop, report console errors.
- Any verification fails twice → stop, report.
