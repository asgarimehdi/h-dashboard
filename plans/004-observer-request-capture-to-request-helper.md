# Plan 004: Use request() helper instead of Request::capture() in HardwareAuditObserver

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 70e35c2..HEAD -- app/Observers/HardwareAuditObserver.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `70e35c2`, 2026-09-01

## Why this matters

`HardwareAuditObserver` records `ip_address` and `user_agent` via `Request::capture()` and `request()->route()` vs `Request::capture()`. `Request::capture()` rebuilds a new request from PHP superglobals, not the current request bound in the container. In tests (which use `actingAs` + `postJson` with synthetic requests), in queued jobs, and in scheduled commands (`todos:generate-recurring` etc. that may touch hardware), the captured request has no `Authorization` header, wrong IP, and no route — so `detectSource()` misclassifies as `web` and audit rows get `null` IP/UA. The bulk controller already uses `request()` correctly; the observer should too.

## Current state

File `app/Observers/HardwareAuditObserver.php` at `70e35c2`:

Lines 1-9:
```php
use Illuminate\Support\Facades\Request;
```

Lines 97-107 (`recordRollbackAudit`):
```php
public function recordRollbackAudit(Hardware $hardware, array $rollbackChanges, ?int $userId = null): void
{
    HardwareAudit::create([
        'hardware_id' => $hardware->id,
        'user_id' => $userId ?? Auth::id(),
        'action' => 'rollback',
        'changes' => $rollbackChanges,
        'source' => $this->detectSource(),
        'ip_address' => Request::capture()->ip(),
        'user_agent' => Request::capture()->userAgent(),
    ]);
}
```

Lines 113-126 (`detectSource`):
```php
protected function detectSource(): string
{
    $route = request()->route(); // already correct helper
    if ($route && $route->getName() === 'hardware.import') {
        return 'import';
    }
    if ($route && str_starts_with($route->uri(), 'api/')) {
        return 'api';
    }
    return 'web';
}
```

Lines 131-145 (`recordAudit`):
```php
protected function recordAudit(Hardware $hardware, string $action, ?array $changes, string $source, ?int $hardwareId = null): void
{
    $user = Auth::user();
    $request = Request::capture(); // <-- bug

    HardwareAudit::create([
        'hardware_id' => $hardwareId ?? $hardware->id,
        'user_id' => $user?->id,
        'action' => $action,
        'changes' => $changes,
        'source' => $source,
        'ip_address' => $request?->ip(),
        'user_agent' => $request?->userAgent(),
    ]);
}
```

Correct reference in `app/Http/Controllers/Api/HardwareController.php:426`:
```php
$request = request(); // actual request, not Request::capture()
```

Repo conventions (AGENTS.md):

- Use `request()` helper or injected `Request $request` for current request (see `HardwareAuditController:23` and `HardwareController:73`) — AGENTS.md **Laravel Boost** recommends `request()` / `request()->route()` over `Request::capture()` which bypasses container binding.
- `Auth::user()` is fine for user; keep it.
- `detectSource` already uses `request()->route()` — keep it; it correctly distinguishes `hardware.import` vs `api/` vs `web` (AGENTS.md **Scheduler** notes import detection by route name).
- Tests at `tests/Feature/HardwareAuditObserverTest.php` assert audits are created but currently expect `ip_address` may be null — after fix, tests should see the test request's IP (`127.0.0.1`) when run via `postJson`. Pest via `composer test` (hermetic, `XDEBUG_MODE=off`, `config:clear+route:clear`) is required (AGENTS.md **Running Tests**).
- Code intelligence: `codegraph explore "HardwareAuditObserver"` before editing; observer is registered in `AppServiceProvider` (AGENTS.md **HardwareAudit**).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 70e35c2..HEAD -- app/Observers/HardwareAuditObserver.php` | empty |
| Lint | `vendor/bin/pint --dirty --format agent` (AGENTS.md **Formatting**) | exit 0 |
| Tests | `composer test` — `config:clear + route:clear + XDEBUG_MODE=off php artisan test` (AGENTS.md **Running Tests**) | 884 baseline pass |
| Single file | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditObserverTest.php` | pass |
| CodeGraph | `codegraph explore "HardwareAuditObserver"` | symbols |

## Scope

**In scope**:

- `app/Observers/HardwareAuditObserver.php`
- `tests/Feature/HardwareAuditObserverRequestTest.php` (create)

**Out of scope**:

- `app/Http/Controllers/Api/HardwareController.php` — already correct, don't touch
- `app/Http/Controllers/Api/HardwareAuditController.php`
- Any other observer

## Git workflow

- Branch: `advisor/004-observer-request-helper` (from `kimya`/`main`, AGENTS.md **New Features** flow)
- Commit: `fix(audit): use current request helper in HardwareAuditObserver`
- Do NOT push — plans only, no execution per user request
- Frontend: observer-only, no `npm run build` needed

## Steps

### Step 1: Replace `Request::capture()` with `request()` in observer

In `app/Observers/HardwareAuditObserver.php`:

1. Remove `use Illuminate\Support\Facades\Request;` import (keep `Auth`).
2. In `recordRollbackAudit` (line 104-106), change:
   ```php
   // before
   'ip_address' => Request::capture()->ip(),
   'user_agent' => Request::capture()->userAgent(),
   // after
   'ip_address' => request()?->ip(),
   'user_agent' => request()?->userAgent(),
   ```
3. In `recordAudit` (line 134), change:
   ```php
   // before
   $request = Request::capture();
   // after
   $request = request();
   ```
   Keep the nullsafe `?->ip()` / `?->userAgent()` as already used (the global `request()` returns `null` outside HTTP context, e.g., in `php artisan tinker` or scheduler — nullsafe is correct).

Keep `detectSource` unchanged (already uses `request()->route()`).

**Verify**: `vendor/bin/pint --dirty --format agent` → 0. `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditObserverTest.php` → pass. `grep -n "Request::capture" app/Observers/HardwareAuditObserver.php` → no matches.

### Step 2: Write regression tests for IP/UA capture

Create `tests/Feature/HardwareAuditObserverRequestTest.php` (Pest), modeled on `tests/Feature/HardwareAuditObserverTest.php`:

- Cases:
  1. `audit captures ip from test request` — `actingAs($user,'sanctum')->postJson('/api/hardware', [...valid...])` → fetch the created audit → `expect($audit->ip_address)->toBe('127.0.0.1')` (PHPUnit test client IP)
  2. `audit captures user_agent from request` — send with `->withHeaders(['User-Agent'=>'FlutterTest/1.0'])` → `expect($audit->user_agent)->toContain('FlutterTest')`
  3. `audit has null ip outside http context` — directly `Hardware::create([...])` without a request (in test, after `request()` is set, reset by calling in `withoutMiddleware`? Simpler: assert that creating hardware via tinker-like path still creates audit with nullable ip — just call `Hardware::create` inside test without `actingAs` and assert `ip_address` is either `127.0.0.1` or `null` but not throw). More robust: `expect(fn()=> Hardware::create([...]))->not->toThrow()` and audit exists.
  4. `rollback audit captures ip from request` — `POST /api/hardware/{id}/audits/{auditId}/rollback` with header → rollback audit has same IP

Use existing test helpers for creating units/persons/users. Ensure `covers` annotation: `@covers \App\Observers\HardwareAuditObserver`.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditObserverRequestTest.php` → 4 passed. `composer test` → full suite passes.

## Test plan

- New file `tests/Feature/HardwareAuditObserverRequestTest.php` with 4 Pest tests above.
- Pattern: `tests/Feature/HardwareAuditObserverTest.php:1-40` (setup, `RefreshDatabase` or `InteractsWithTestSetup`, `actingAs`).
- Verify IP is not `null` when request exists — this is the behavioral proof that `request()` is bound correctly.

## Done criteria

- [ ] `vendor/bin/pint --dirty --format agent` exits 0
- [ ] `grep -rn "Request::capture" app/` returns no matches (or only in comments)
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditObserverRequestTest.php` exits 0
- [ ] `composer test` exits 0
- [ ] `app/Observers/HardwareAuditObserver.php` imports only `Auth` and `Hardware/HardwareAudit`, no `Request` facade
- [ ] No out-of-scope files modified
- [ ] `plans/README.md` row for 004 updated to DONE

## STOP conditions

- The observer file at `app/Observers/HardwareAuditObserver.php:131-145` does not match excerpt (drift — maybe audit logic moved to service).
- `request()` helper is unavailable in observer context due to not being bound (e.g., in queue worker without HTTP) — this is expected to return `null`; the nullsafe `?->ip()` handles it, but if tests show exception, stop and report.
- Existing `HardwareAuditObserverTest` fails because it asserts `ip_address` is `null` — update that test to assert `not null` when run via HTTP, don't weaken the fix.
- Fix requires changing `detectSource` — stop, this plan leaves it as-is.

## Maintenance notes

- If hardware creation moves to queued jobs, `request()` will be `null` there — audit will have `null` IP/UA which is correct for non-HTTP sources. If you need IP in jobs, pass it explicitly from controller.
- Reviewer: check that `request()?->ip()` nullsafe does not hide bugs — in HTTP context it must be non-null; the test proves it.
- Follow-up: centralize `recordAudit` to accept `?Request $request = null` for testability, but out of scope for this S-sized fix.
