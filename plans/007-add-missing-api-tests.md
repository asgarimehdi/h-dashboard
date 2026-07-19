# Plan 007: Add missing feature tests for API controllers

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat <planned-at SHA>..HEAD -- tests/`
> If any test file changed since this plan was written, compare the
> "Current state" against the live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `b585f88`, 2024-07-17
- **Issue**: N/A

## Why this matters

Four API controllers have zero feature tests:
- `UnitController` (accessible units listing)
- `LocationController` (store location log)
- `TrafficController` (Zabbix traffic data)
- `MultiLatestValueController` (Zabbix latest values)

Without tests, any change to these controllers or the underlying ZabbixService is unverified. The existing `TodoApiTest` and `AccessServiceTest` show the pattern — use `RefreshDatabase`, Sanctum auth, and session-based unit context.

## Current state

**Existing test pattern** — `tests/Feature/TodoApiTest.php`:
- Uses `RefreshDatabase` trait
- Creates User + Unit via factory, attaches them, sets `session('current_unit_id')`
- Calls `actingAs($user, 'sanctum')` for Sanctum auth
- Asserts response status, JSON structure, and database state

**Existing test pattern** — `tests/Feature/AccessServiceTest.php`:
- Uses `RefreshDatabase`, creates Unit hierarchy, tests `AccessService::accessibleUnitIds()`
- Tests cache clearing

**Controllers needing tests:**
1. `app/Http/Controllers/Api/UnitController.php` — `__invoke()` returns paginated accessible units
2. `app/Http/Controllers/Api/LocationController.php` — `store()` validates lat/lng and creates LocationLog
3. `app/Http/Controllers/Api/TrafficController.php` — `index()` calls ZabbixService, returns cached data
4. `app/Http/Controllers/Api/MultiLatestValueController.php` — `index()` calls ZabbixService, returns cached data

**Route file**: `routes/api.php` — all routes are under `auth:sanctum` middleware.

## Commands you will need

| Purpose   | Command                  | Expected on success |
|-----------|--------------------------|---------------------|
| Test      | `php artisan test --compact` | all pass |
| Syntax    | `php -l tests/Feature/` | `No syntax errors detected` |
| Lint      | `vendor/bin/pint --test` | `All files pass` |

## Scope

**In scope** (the only files you should modify):
- `tests/Feature/UnitApiTest.php` (create)
- `tests/Feature/LocationApiTest.php` (create)

**Out of scope** (do NOT touch, even though they look related):
- `app/Services/ZabbixService.php` — TrafficController and MultiLatestValueController depend on external Zabbix API; mock the service instead of calling the real API. Do not modify ZabbixService in this plan.
- `app/Http/Controllers/Api/TrafficController.php` — skip for now (requires mocking ZabbixService)
- `app/Http/Controllers/Api/MultiLatestValueController.php` — skip for now (requires mocking ZabbixService)
- Existing test files (do not modify)
- Source code, migrations, or config files

## Git workflow

- Branch: `advisor/007-add-missing-api-tests` (or the repo's branch-naming convention if one is evident)
- Commit per test file; message style: `test: add feature tests for UnitController and LocationController`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Create `tests/Feature/UnitApiTest.php`

Create the file with the following structure (model after `tests/Feature/TodoApiTest.php`):

```php
<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class UnitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    public function unauthenticated_user_cannot_access_units(): void
    {
        $this->getJson('/api/unit')->assertStatus(401);
    }

    public function user_can_list_accessible_units(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/unit');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function user_cannot_see_inaccessible_units(): void
    {
        $user = User::factory()->create();
        $accessible = Unit::factory()->create();
        $inaccessible = Unit::factory()->create();
        $user->units()->attach($accessible->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $accessible->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/unit');

        $response->assertStatus(200);
        $unitIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($accessible->id, $unitIds);
        $this->assertNotContains($inaccessible->id, $unitIds);
    }
}
```

**Verify**: `php -l tests/Feature/UnitApiTest.php` → `No syntax errors detected`

### Step 2: Create `tests/Feature/LocationApiTest.php`

Create the file with the following structure:

```php
<?php

namespace Tests\Feature;

use App\Models\LocationLog;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    public function unauthenticated_user_cannot_save_location(): void
    {
        $this->postJson('/api/location', [
            'latitude' => 35.7,
            'longitude' => 51.4,
        ])->assertStatus(401);
    }

    public function user_can_save_location(): void
    {
        $unit = Unit::factory()->create();
        $user = User::factory()->create();
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/location', [
            'latitude' => 35.6892,
            'longitude' => 51.3890,
        ]);

        $response->assertStatus(201)
            ->assertJson(['message' => 'Location saved successfully.']);

        $this->assertDatabaseHas('location_logs', [
            'user_id' => $user->id,
            'latitude' => 35.6892,
            'longitude' => 51.3890,
        ]);
    }

    public function location_validation_rejects_invalid_coordinates(): void
    {
        $user = User::factory()->create();
        Session::put('current_unit_id', 1);

        $this->actingAs($user, 'sanctum')->postJson('/api/location', [
            'latitude' => 100,   // out of range
            'longitude' => 50,
        ])->assertStatus(422);
    }

    public function location_save_requires_unit_context(): void
    {
        $user = User::factory()->create();
        // No units attached, no session set

        $this->actingAs($user, 'sanctum')->postJson('/api/location', [
            'latitude' => 35.7,
            'longitude' => 51.4,
        ])->assertStatus(422);
    }
}
```

**Verify**: `php -l tests/Feature/LocationApiTest.php` → `No syntax errors detected`

### Step 3: Run the new tests

`php artisan test --compact --filter=UnitApiTest`

**Verify**: All 3 tests pass

`php artisan test --compact --filter=LocationApiTest`

**Verify**: All 4 tests pass

### Step 4: Run the full test suite

`php artisan test --compact`

**Verify**: No NEW failures

## Test plan

- New tests: `UnitApiTest` (3 tests), `LocationApiTest` (4 tests)
- Pattern: model after `tests/Feature/TodoApiTest.php`
- Skip `TrafficController` and `MultiLatestValueController` tests in this plan (require Zabbix mocking — file a follow-up)

## Done criteria

ALL must hold:

- [ ] `php artisan test --compact --filter=UnitApiTest` passes (3 tests)
- [ ] `php artisan test --compact --filter=LocationApiTest` passes (4 tests)
- [ ] `php artisan test --compact` exits with no NEW failures
- [ ] `tests/Feature/UnitApiTest.php` exists and has 3 tests
- [ ] `tests/Feature/LocationApiTest.php` exists and has 4 tests
- [ ] No files outside `tests/Feature/` are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- `php artisan test` fails with database connection errors (MySQL not running — report blocker).
- A step's verification fails twice after a reasonable fix attempt.
- The fix appears to require touching an out-of-scope file.
- The `LocationController` or `UnitController` code has changed since this plan was written.

## Maintenance notes

- TrafficController and MultiLatestValueController tests should be added in a follow-up plan (requires mocking ZabbixService).
- If new API endpoints are added, follow the same pattern: create a `<Name>ApiTest.php` file.
- Tests use `RefreshDatabase` which requires MySQL. If SQLite in-memory testing is desired, update `phpunit.xml` to uncomment the DB_CONNECTION lines.