# Plan 9: SecurityHeaders middleware has zero test coverage

> Written against commit: `c35f57b` (sydney)
> Category: Test | Effort: S | Impact: HIGH

## Problem

`SecurityHeaders` middleware (`app/Http/Middleware/SecurityHeaders.php`) is registered globally on every web request via `bootstrap/app.php:35-38` yet has **zero test coverage**. No file in `tests/` references this middleware.

This means:
- A future regression could silently drop `X-Frame-Options: DENY` (clickjacking) or `Content-Security-Policy-Report-Only` (XSS defense) without any CI signal.
- The CSP report-only directive is a placeholder before enforcement — losing it in production would regress the planned enforcement timeline.
- HSTS header removal would silently weaken HTTPS protections behind the load balancer.

### Evidence

- `app/Http/Middleware/SecurityHeaders.php:15-22` — 5 headers set (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy-Report-Only`, `Strict-Transport-Security`)
- `bootstrap/app.php:35-37` — registered globally: `$middleware->web(append: [SecurityHeaders::class, ...])`
- `tests/Feature/SafeRoleOrPermissionMiddlewareTest.php`, `tests/Feature/LastUserActivityMiddlewareTest.php`, `tests/Feature/ValidateUnitContextTest.php` — three other middleware have Pest tests; SecurityHeaders has none

### Existing test patterns

All middleware tests in this project follow the same Pest structure:
```php
covers(ClassName::class);
uses(TestCase::class, RefreshDatabase::class);
test('description', function () { ... });
```

`SafeRoleOrPermissionMiddlewareTest` tests via HTTP requests (`$this->get('/test-safe-route')`).
`ValidateUnitContextTest` instantiates middleware directly:
```php
$middleware = new ValidateUnitContext;
$response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));
```

## Solution

Create `tests/Feature/SecurityHeadersMiddlewareTest.php` using the HTTP-request pattern (since SecurityHeaders is globally registered, making a request to any web route will exercise it).

### Test cases to cover

1. **All 5 headers present on a standard web response** — `GET /` (redirects to `/dashboard` but still passes through middleware)
2. **Header values correct** — assert exact values, not just presence
3. **CSP report-only directive has expected content** — assert it contains `default-src 'self'` and `report-uri /csp-report`
4. **HSTS value is correct** — `max-age=31536000; includeSubDomains`
5. **API responses also receive headers** — the middleware is web-only, verify API routes do NOT get them (boundary test)
6. **Middleware does not interfere with redirects** — 302 redirects still carry the headers

### Before (does not exist)

No test file.

### After

```php
<?php

use App\Http\Middleware\SecurityHeaders;
use Tests\TestCase;

covers(SecurityHeaders::class);

uses(TestCase::class);

test('SecurityHeaders middleware sets X-Content-Type-Options on web responses', function () {
    $response = $this->get('/dashboard');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('SecurityHeaders middleware sets X-Frame-Options DENY', function () {
    $response = $this->get('/dashboard');

    $response->assertHeader('X-Frame-Options', 'DENY');
});

test('SecurityHeaders middleware sets Referrer-Policy', function () {
    $response = $this->get('/dashboard');

    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('SecurityHeaders middleware sets Content-Security-Policy-Report-Only', function () {
    $response = $this->get('/dashboard');

    $response->assertHeader('Content-Security-Policy-Report-Only');
    $csp = $response->headers->get('Content-Security-Policy-Report-Only');
    expect($csp)->toContain("default-src 'self'");
    expect($csp)->toContain("frame-ancestors 'none'");
    expect($csp)->toContain('report-uri /csp-report');
});

test('SecurityHeaders middleware sets HSTS header', function () {
    $response = $this->get('/dashboard');

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('SecurityHeaders middleware sets all five headers in a single request', function () {
    $response = $this->get('/dashboard');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Content-Security-Policy-Report-Only');
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
```

> **Note:** `GET /dashboard` requires authentication (302 → /login for guests). Two options:
> - Use `$this->get('/login')` which returns 200 without auth.
> - Or authenticate first: `$user = User::factory()->create(); $this->actingAs($user); $this->get('/dashboard');`
>
> The `/login` route is simplest since SecurityHeaders is applied globally to all web routes.

## Files in Scope

- `tests/Feature/SecurityHeadersMiddlewareTest.php` (new)

## Files Out of Scope

- `app/Http/Middleware/SecurityHeaders.php` (no changes — middleware is correct)
- `bootstrap/app.php` (registration is correct, no changes)
- API route tests (boundary test only, not API middleware changes)

## Steps

### Step 1: Write the test file
1. Create `tests/Feature/SecurityHeadersMiddlewareTest.php` with the 6 test cases above
2. Use `/login` as the test route (returns 200 without auth) OR authenticate then hit `/dashboard`
3. Verify: `grep -c "SecurityHeaders" tests/Feature/SecurityHeadersMiddlewareTest.php` returns 1 (covers annotation)

### Step 2: Run the tests
1. `composer test -- tests/Feature/SecurityHeadersMiddlewareTest.php` — all 6 tests pass
2. `composer test` — full suite still passes (no regressions)

### Step 3: Run quality gates
1. `composer pint` — format the new file
2. `composer phpstan` — no new errors

### Step 4: Commit and push
1. `git add tests/Feature/SecurityHeadersMiddlewareTest.php`
2. `git commit -m "test(SecurityHeaders): add test coverage for all 5 security headers"`
3. `git push`

## Test Plan

1. Run `composer test -- tests/Feature/SecurityHeadersMiddlewareTest.php` — 6 tests pass
2. Verify each test is meaningful: temporarily remove one header set in the middleware, confirm the corresponding test fails
3. Run full suite: `composer test` — all 1352+ tests pass
4. Run `composer phpstan` — no new errors
5. Run `composer pint` — no formatting issues

## Maintenance Note

- If a new header is added to SecurityHeaders, a corresponding test assertion should be added here
- CSP directives will change when moving from report-only to enforcement — update the test to match
- If the middleware is ever moved from web-global to a named route group, the test route must be updated to match
- The `covers()` annotation ensures this file appears in coverage reports for the SecurityHeaders class

## Done Criteria

- [ ] `tests/Feature/SecurityHeadersMiddlewareTest.php` exists with ≥5 test cases
- [ ] `covers(SecurityHeaders::class)` annotation present
- [ ] All tests in the file pass: `composer test -- tests/Feature/SecurityHeadersMiddlewareTest.php`
- [ ] Full test suite passes: `composer test`
- [ ] PHPStan clean: `composer phpstan`
- [ ] Pint formatted: `composer pint`
