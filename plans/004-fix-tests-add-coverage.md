# Plan 004: Fix dead TicketWorkflowTest + add SecurityHeaders + UnitScopedRequest tests

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the STOP conditions occurs, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat 5f9c24e..HEAD -- tests/Feature/TicketWorkflowTest.php tests/Feature/SecurityHeadersTest.php tests/Feature/UnitScopedRequestTest.php`

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: HIGH
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `5f9c24e`, 2026-09-12

## ⚠️ TL;DR فارسی

**مشکل:** ۷ تست فقط اسم‌شون `ticket_` هست نه `test_` — PHPUnit نمی‌بینه. SecurityHeaders تست نداره.

**راه‌حل:** rename + ۲ فایل تست جدید.

**ریسک:** 🟢 صفر


## Why this matters
`TicketWorkflowTest` contains 7 test methods that are never discovered or executed by PHPUnit/Pest because they use the `ticket_*` naming convention instead of `test_*`. This means zero test coverage for the core ticket lifecycle (create → forward → accept → complete → reject). Additionally, the `SecurityHeaders` middleware and `UnitScopedRequest` form request have zero test coverage, despite being security-critical components.

## Current state

### Dead tests: `tests/Feature/TicketWorkflowTest.php`
All 7 methods are named `ticket_*` instead of `test_*`:

```php
// Line 18:
public function ticket_has_created_status_by_default(): void
// Line 28:
public function ticket_can_be_forwarded(): void
// Line 39:
public function ticket_accepted_sets_accepted_at(): void
// Line 57:
public function ticket_completed_sets_completed_at(): void
// Line 76:
public function ticket_rejected_status_works(): void
// Line 87:
public function ticket_timestamps_are_cast(): void
// Line 103:
public function ticket_factory_produces_valid_data(): void
```

PHPUnit discovers tests by methods starting with `test` (case-insensitive) or annotated with `@test`. None of these qualify. The class extends `TestCase` and uses `RefreshDatabase`, so the tests are valid — just invisible.

### Uncovered security middleware: `app/Http/Middleware/SecurityHeaders.php`
```php
// Lines 15-18:
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('X-Frame-Options', 'DENY');
$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
$response->headers->set('X-XSS-Protection', '1; mode=block');
```
Zero test coverage. If these headers are accidentally removed or changed, nothing catches it.

### Uncovered form request: `app/Http/Requests/UnitScopedRequest.php`
```php
// Lines 18-22:
public function accessibleIds(): array
{
    return $this->accessibleIds ??= app(AccessService::class)
        ->accessibleUnitIds($this->user());
}

// Lines 28-35:
public function assertAccessibleUnit(int $unitId): JsonResponse|true
{
    if (! in_array($unitId, $this->accessibleIds())) {
        return response()->json(['message' => 'Unit not accessible.'], 403);
    }
    return true;
}
```
This is the authorization backbone for unit-scoped requests. No dedicated test exists.

## Commands you will need
| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 5f9c24e..HEAD -- tests/` | No changes |
| Run tests before fix | `composer test -- --filter TicketWorkflowTest` | 0 tests found |
| Run tests after rename | `composer test -- --filter TicketWorkflowTest` | 7 tests run, all pass |
| Run new SecurityHeaders test | `composer test -- --filter SecurityHeadersTest` | Tests pass |
| Run new UnitScopedRequest test | `composer test -- --filter UnitScopedRequestTest` | Tests pass |
| Format | `vendor/bin/pint --dirty --format agent` | Clean |

## Scope
**In scope**: `tests/Feature/TicketWorkflowTest.php` (rename methods), new `tests/Feature/SecurityHeadersTest.php`, new `tests/Feature/UnitScopedRequestTest.php`.
**Out of scope**: Other test files with coverage gaps; Pest migration of existing PHPUnit tests; testing other middlewares or form requests.

## Git workflow
- Branch: `advisor/004-fix-tests-add-coverage`

## Steps

### Step 1: Create the feature branch
```bash
git checkout celin
git checkout -b advisor/004-fix-tests-add-coverage
```
**Verify**: `git branch --show` → `advisor/004-fix-tests-add-coverage`

### Step 2: Confirm the dead tests
```bash
composer test -- --filter TicketWorkflowTest 2>&1
```
**Verify**: Output shows 0 tests executed (or no matching test found).

### Step 3: Rename test methods in TicketWorkflowTest.php
Add `test_` prefix to all 7 methods in `tests/Feature/TicketWorkflowTest.php`:

| Line | Before | After |
|------|--------|-------|
| 18 | `ticket_has_created_status_by_default` | `test_has_created_status_by_default` |
| 28 | `ticket_can_be_forwarded` | `test_can_be_forwarded` |
| 39 | `ticket_accepted_sets_accepted_at` | `test_accepted_sets_accepted_at` |
| 57 | `ticket_completed_sets_completed_at` | `test_completed_sets_completed_at` |
| 76 | `ticket_rejected_status_works` | `test_rejected_status_works` |
| 87 | `ticket_timestamps_are_cast` | `test_timestamps_are_cast` |
| 103 | `ticket_factory_produces_valid_data` | `test_factory_produces_valid_data` |

**Verify**: `composer test -- --filter TicketWorkflowTest` → 7 tests found and all pass.

### Step 4: Create SecurityHeadersTest.php
Create `tests/Feature/SecurityHeadersTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present_on_response(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }
}
```

Note: The root route `/` is a Livewire route (`index` component, see `routes/web.php:55`). If it redirects (e.g., to login), adjust the test to use a route that's accessible unauthenticated or use `$this->get('/login')` instead. Verify which route is accessible and adjust accordingly.

**Verify**: `composer test -- --filter SecurityHeadersTest` → tests pass.

### Step 5: Create UnitScopedRequestTest.php
Create `tests/Feature/UnitScopedRequestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Http\Requests\UnitScopedRequest;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UnitScopedRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_accessible_ids_returns_units_for_user(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        // Attach unit to user via user_units pivot
        $user->units()->attach($unit->id);

        $request = UnitScopedRequest::create(
            '/test',
            'GET',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $request->setUserResolver(fn() => $user);

        $accessibleIds = $request->accessibleIds();

        $this->assertContains($unit->id, $accessibleIds);
    }

    public function test_assert_accessible_unit_returns_true_for_accessible(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();
        $user->units()->attach($unit->id);

        $request = UnitScopedRequest::create(
            '/test',
            'GET',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $request->setUserResolver(fn() => $user);

        $result = $request->assertAccessibleUnit($unit->id);

        $this->assertTrue($result);
    }

    public function test_assert_accessible_unit_returns_403_for_inaccessible(): void
    {
        $user = User::factory()->create();
        $accessibleUnit = Unit::factory()->create();
        $inaccessibleUnit = Unit::factory()->create();
        $user->units()->attach($accessibleUnit->id);

        $request = UnitScopedRequest::create(
            '/test',
            'GET',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $request->setUserResolver(fn() => $user);

        $response = $request->assertAccessibleUnit($inaccessibleUnit->id);

        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $this->assertEquals(403, $response->getStatusCode());
    }
}
```

**Important**: Verify the pivot table name and relationship method name. The project uses `user_units` pivot table (per AGENTS.md). Check the User model for the `units()` relationship name:
```bash
grep -n "function units" app/Models/User.php
```
If the method is named differently, adjust the test accordingly.

**Verify**: `composer test -- --filter UnitScopedRequestTest` → tests pass.

### Step 6: Format
```bash
vendor/bin/pint --dirty --format agent
```
**Verify**: No errors.

### Step 7: Run full test suite
```bash
composer test
```
**Verify**: All tests pass (including the 3 newly active/created test files).

### Step 8: Commit
```bash
git add tests/Feature/TicketWorkflowTest.php tests/Feature/SecurityHeadersTest.php tests/Feature/UnitScopedRequestTest.php
git commit -m "test: fix dead TicketWorkflowTest + add SecurityHeaders + UnitScopedRequest tests

- Rename ticket_* methods to test_* in TicketWorkflowTest (7 tests were
  invisible to PHPUnit)
- Add SecurityHeadersTest asserting X-Content-Type-Options, X-Frame-Options,
  Referrer-Policy, and X-XSS-Protection headers
- Add UnitScopedRequestTest for accessible/inaccessible unit assertions"
```

## Test plan
1. `composer test -- --filter TicketWorkflowTest` → 7 tests run, all green
2. `composer test -- --filter SecurityHeadersTest` → Security header assertions pass
3. `composer test -- --filter UnitScopedRequestTest` → 3 tests (accessible, true for accessible, 403 for inaccessible), all green
4. `composer test` → full suite passes (no regressions)

## Done criteria
- [ ] All 7 `TicketWorkflowTest` methods start with `test_` and are discovered
- [ ] `SecurityHeadersTest` exists and asserts all 4 headers
- [ ] `UnitScopedRequestTest` exists and tests accessible + inaccessible unit scenarios
- [ ] `composer test` passes with no regressions
- [ ] `vendor/bin/pint --dirty` shows no formatting issues

## STOP conditions
- If renaming methods causes Pest to fail (e.g., Pest doesn't recognize `test_*` in a class-based test) — Pest should recognize `test_*` in both class-based and function-based tests, but verify
- If the SecurityHeaders middleware is not registered in the global middleware stack — check `app/Http/Kernel.php` or `bootstrap/app.php` and adjust the test route
- If the User model doesn't have a `units()` relationship — inspect `app/Models/User.php` and adjust the UnitScopedRequestTest accordingly
- If existing tests regress after the rename — stop and investigate

## Maintenance notes
- The project uses Pest (per AGENTS.md: "Testing: Pest via `composer test`"), but `TicketWorkflowTest.php` is a PHPUnit class test extending `TestCase`. Pest discovers both formats — verify Pest runs class-based tests by default. If not, consider migrating to Pest function syntax: `it('has created status by default', function () { ... })->covers(Ticket::class);`
- Consider adding `@covers(Ticket::class)` as a Pest `->covers()` call instead of the PHPDoc `covers()` on line 12 if migrating to Pest syntax
- Future test files should use `test_*` or Pest `it()` syntax to avoid the naming discovery issue
- The SecurityHeaders test hits the root route `/` which requires `auth` + `unit_context` middleware. If it redirects to login, the test still validates headers on the redirect response, OR use a public route. Adjust the route if needed based on actual middleware behavior.
