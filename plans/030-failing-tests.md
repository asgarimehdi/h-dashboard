# 030 — Fix Failing Tests

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | HIGH (Quality) |
| **Effort** | M |
| **Risk** | Low — test-only changes |
| **Base commit** | `a106d38` |
| **Files** | `tests/Feature/AuthTest.php`, `tests/Feature/LogoutTest.php`, `tests/Feature/KargoziniImportLivewireTest.php`, `tests/Feature/HardwareImportLivewireTest.php`, `tests/Feature/ImportsLivewireTest.php`, `tests/Feature/TicketsInboxLivewireTest.php` |

## Problem

Current test run shows **14 failures** (not 190 — the 190 figure was from a prior state). The failures fall into clear categories:

### Evidence (file:line)

#### Category A: CSRF 419 on logout (3 failures)

- **`tests/Feature/AuthTest.php:69-76`** — `test('logout invalidates session and redirects')` — `$this->post('/logout')` gets 419 instead of 302.
- **`tests/Feature/LogoutTest.php`** — 3 tests fail with 419: `logout redirects to home`, `logout invalidates session`, `logout creates activity log`.

The `POST /logout` route requires CSRF token verification. In tests using `$this->actingAs()`, the CSRF token is not automatically included. Laravel's test requests should bypass CSRF by default in the testing environment, but if the route uses a middleware that re-enables it, the 419 occurs.

#### Category B: Livewire component interaction failures (8 failures)

- **`tests/Feature/KargoziniImportLivewireTest.php:232`** — `valid preview populates table` and related tests
- **`tests/Feature/HardwareImportLivewireTest.php`** — `compare key repreview`, `cancel resets`, `row override skip`
- **`tests/Feature/ImportsLivewireTest.php`** — `hardware import component shows`, `person import component shows`
- **`tests/Feature/TicketsInboxLivewireTest.php`** — `file uploads`

These are likely Livewire component interaction failures. The parallel test run may show different failures than serial due to shared state.

#### Category C: Guest cannot access logout (1 failure)

- **`tests/Feature/LogoutTest.php`** — `guest cannot access logout` — likely expects 403 or redirect, gets different status.

## Decision

### For CSRF 419 on logout

The `POST /logout` route is defined in `routes/web.php`. In Laravel, HTTP testing via `$this->post()` should not require CSRF tokens (they're disabled in `TestCase`). If a 419 occurs, it means either:
1. The `VerifyCsrfToken` middleware is not properly excluded for the test environment, OR
2. There's a custom middleware that re-validates tokens.

**Fix:** Ensure the test uses `$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)` or confirm the middleware is properly disabled in `phpunit.xml`. Alternatively, use `$this->call('POST', '/logout')` which bypasses middleware.

The simplest fix: In the logout tests, add `->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])` or use the `$this->assertAuthenticated()` + session approach.

### For Livewire component failures

These may be related to the parallel test execution or to missing PermissionSeeder calls. Investigate each failure individually.

## Commands

```bash
cd /home/runner/h-dashboard
# Run just the failing test classes to see exact errors
XDEBUG_MODE=off php artisan test tests/Feature/AuthTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/LogoutTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/KargoziniImportLivewireTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/HardwareImportLivewireTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/ImportsLivewireTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/TicketsInboxLivewireTest.php -v
```

## Steps

### Phase 1 — Diagnose CSRF 419 on logout

1. Read `routes/web.php` to find the logout route definition.
2. Read `tests/Feature/LogoutTest.php` and `tests/Feature/AuthTest.php` to understand the test pattern.
3. Check if `phpunit.xml` properly sets `APP_ENV=testing` and disables CSRF.
4. Fix by either:
   - Adding `$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)` in the test `setUp()`, OR
   - If the route is in `routes/web.php` and uses `auth` middleware, ensure the session auth works with `actingAs()`.
   - Alternatively, if the logout route accepts `_token` in the body, ensure the test sends it.

### Phase 2 — Fix Livewire component tests

For each failing Livewire test:
1. Run the specific test with `-v` to see the exact assertion failure.
2. Check if the test needs `PermissionSeeder` seeded in `setUp()`.
3. Check if the test is interacting with a component that requires a different auth setup.
4. Fix the specific assertion or setup.

### Phase 3 — Run full suite serial and parallel

```bash
XDEBUG_MODE=off php artisan test                    # Serial
XDEBUG_MODE=off php artisan test --parallel          # Parallel
composer test                                        # Full clean run
```

### Phase 4 — Verify fix stability

Run the previously-failing tests 3 times to confirm no flakiness:
```bash
for i in 1 2 3; do
  XDEBUG_MODE=off php artisan test tests/Feature/AuthTest.php tests/Feature/LogoutTest.php --no-coverage 2>&1 | tail -5
done
```

## Test plan

- All 14 currently-failing tests should pass.
- Run full suite to ensure no regressions.
- Run in parallel mode to confirm no parallel-specific failures.

## Done criteria

- [ ] 0 failures in `composer test`
- [ ] 0 failures in `composer test --parallel`
- [ ] All 14 previously-failing tests pass 3 consecutive runs
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If fixing one test class breaks another, STOP and investigate shared state.
- If the CSRF issue requires changing `VerifyCsrfToken` globally (not just in tests), STOP and report — that's a security decision.
- If a test failure is due to a genuine bug (not test setup), STOP and file a separate bug report.
