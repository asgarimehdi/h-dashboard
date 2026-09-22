# Plan 10: HardwareUpdated event + HardwareGisCacheListener completely untested

> Written against commit: `7ea45a9` (beta/sydney)
> Category: Test | Effort: S | Impact: HIGH

## Problem

`HardwareUpdated` event and `HardwareGisCacheListener` have **zero test coverage**. No file in `tests/` references either class. The event is dispatched in 5 places across `HardwareController` on every hardware mutation (create, update, delete, bulk_mark, bulk_deleted), and the listener is the sole handler that invalidates the `gis` cache namespace. A regression that breaks this listener would silently leave stale GIS cache data serving stale map coordinates.

### Evidence

- `app/Events/HardwareUpdated.php:8-16` — event class carrying `Hardware` model + action string (`'created'`, `'updated'`, `'deleted'`, `'bulk_mark'`, `'bulk_deleted'`)
- `app/Listeners/HardwareGisCacheListener.php:14-17` — sole handler: calls `$this->cache->increment('gis')`
- `app/Providers/EventServiceProvider.php:16-19` — registration: `HardwareUpdated::class => [HardwareGisCacheListener::class]`
- `app/Http/Controllers/Api/HardwareController.php:185` — dispatched on create
- `app/Http/Controllers/Api/HardwareController.php:221` — dispatched on update
- `app/Http/Controllers/Api/HardwareController.php:234` — dispatched on delete
- `app/Http/Controllers/Api/HardwareController.php:310` — dispatched on bulk_mark
- `app/Http/Controllers/Api/HardwareController.php:349` — dispatched on bulk_deleted
- `tests/` search for `HardwareUpdated` or `HardwareGisCache` — **zero results**

### Existing test patterns

- `tests/Feature/CacheInvalidationTest.php` — tests `CacheInvalidationService` with `InteractsWithTestSetup` trait and `assertCacheInvalidated()` helper
- `tests/Unit/CacheInvalidationServiceTest.php` — unit tests for the service itself using `TestCase` + `Cache::flush()`
- No `Event::fake()` or `Event::assertDispatched()` usage found anywhere in the test suite — this will be the first event-dispatch test

## Solution

Create two test files covering the event class, the listener behavior, and their integration via `EventServiceProvider` registration.

### Test 1: `tests/Unit/HardwareUpdatedEventTest.php`

Unit test for the event DTO itself — verifies constructor sets properties correctly.

### Test 2: `tests/Feature/HardwareGisCacheListenerTest.php`

Feature test for the listener — verifies dispatching `HardwareUpdated` causes `gis` cache version to increment. Uses `InteractsWithTestSetup` trait (same as `CacheInvalidationTest`).

#### Before (does not exist)

No test files.

#### After

```php
<?php

// tests/Unit/HardwareUpdatedEventTest.php
namespace Tests\Unit;

use App\Events\HardwareUpdated;
use App\Models\Hardware;
use Tests\TestCase;

covers(HardwareUpdated::class);

uses(TestCase::class);

test('HardwareUpdated stores hardware and action', function () {
    $hardware = new Hardware;
    $hardware->id = 1;

    $event = new HardwareUpdated($hardware, 'created');

    expect($event->hardware)->toBe($hardware);
    expect($event->action)->toBe('created');
});

test('HardwareUpdated accepts all action types', function () {
    $hardware = new Hardware;
    $hardware->id = 2;

    foreach (['created', 'updated', 'deleted', 'bulk_mark', 'bulk_deleted'] as $action) {
        $event = new HardwareUpdated($hardware, $action);
        expect($event->action)->toBe($action);
    }
});
```

```php
<?php

// tests/Feature/HardwareGisCacheListenerTest.php
namespace Tests\Feature;

use App\Events\HardwareUpdated;
use App\Models\Hardware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(\App\Listeners\HardwareGisCacheListener::class);

uses(InteractsWithTestSetup::class);
uses(TestCase::class);

test('dispatching HardwareUpdated increments gis cache version', function () {
    Cache::flush();
    $before = Cache::get('gis_version', 0);

    $hardware = $this->createHardware(['pc_name' => 'GIS-Test']);
    event(new HardwareUpdated($hardware, 'created'));

    $after = Cache::get('gis_version', 0);
    expect($after)->toBeGreaterThan($before);
});

test('listener handles every action type without error', function () {
    Cache::flush();
    $hardware = $this->createHardware(['pc_name' => 'Action-Test']);

    foreach (['created', 'updated', 'deleted', 'bulk_mark', 'bulk_deleted'] as $action) {
        event(new HardwareUpdated($hardware, $action));
    }

    expect(Cache::get('gis_version', 0))->toBeGreaterThan(0);
});
```

> **Note:** The `HardwareUpdated` event uses `public` readonly-style properties (`public Hardware $hardware`, `public string $action`) set via constructor promotion — no getters needed.

## Files in Scope

- `tests/Unit/HardwareUpdatedEventTest.php` (new)
- `tests/Feature/HardwareGisCacheListenerTest.php` (new)

## Files Out of Scope

- `app/Events/HardwareUpdated.php` (no changes — event class is correct)
- `app/Listeners/HardwareGisCacheListener.php` (no changes — listener is correct)
- `app/Providers/EventServiceProvider.php` (no changes — registration is correct)
- `app/Http/Controllers/Api/HardwareController.php` (dispatch sites are correct; not modifying controller behavior)

## Steps

### Step 1: Create the Unit test for HardwareUpdated event
1. Create `tests/Unit/HardwareUpdatedEventTest.php` with the 2 test cases above
2. Verify: `grep -c "HardwareUpdated" tests/Unit/HardwareUpdatedEventTest.php` returns ≥1

### Step 2: Create the Feature test for HardwareGisCacheListener
1. Create `tests/Feature/HardwareGisCacheListenerTest.php` with the 2 test cases above
2. Uses `InteractsWithTestSetup` trait for `createHardware()` helper (same as `CacheInvalidationTest.php`)
3. Verify: `grep -c "HardwareGisCacheListener" tests/Feature/HardwareGisCacheListenerTest.php` returns ≥1

### Step 3: Run the new tests
1. `composer test -- tests/Unit/HardwareUpdatedEventTest.php` — 2 tests pass
2. `composer test -- tests/Feature/HardwareGisCacheListenerTest.php` — 2 tests pass
3. `composer test` — full suite still passes (no regressions)

### Step 4: Run quality gates
1. `composer pint` — format the new files
2. `composer phpstan` — no new errors

### Step 5: Commit and push
1. `git add tests/Unit/HardwareUpdatedEventTest.php tests/Feature/HardwareGisCacheListenerTest.php`
2. `git commit -m "test(HardwareUpdated, HardwareGisCacheListener): add unit + feature test coverage — TC-002"`
3. `git push`

## Test Plan

1. Run `composer test -- tests/Unit/HardwareUpdatedEventTest.php` — 2 tests pass
2. Run `composer test -- tests/Feature/HardwareGisCacheListenerTest.php` — 2 tests pass
3. Verify each test is meaningful: temporarily remove `event(new HardwareUpdated(...))` from `HardwareController::store()` (line 185), confirm the listener test fails, then restore
4. Run full suite: `composer test` — all 1352+ tests pass
5. Run `composer phpstan` — no new errors
6. Run `composer pint` — no formatting issues
7. Verify `covers()` annotations are correct: `grep -n "covers(" tests/Unit/HardwareUpdatedEventTest.php tests/Feature/HardwareGisCacheListenerTest.php`

## Maintenance Note

- If new actions are added to `HardwareUpdated` (e.g. `'bulk_restored'` — listed in the comment but not dispatched anywhere yet), add a corresponding test case to the `'accepts all action types'` test
- If a second listener is registered for `HardwareUpdated` in `EventServiceProvider`, add a test verifying that listener is also called (use `Event::fake()` + `Event::assertDispatched()` pattern)
- The `gis` cache namespace version starts at 0 — the tests rely on this; if `PruneStaleCache` is run between cache flush and assertion, it could affect results. The `Cache::flush()` in setUp prevents this.
- The comment on line 14 of `HardwareUpdated.php` mentions `'bulk_restored'` as an action but no code dispatches it — a future plan could add that action or remove the dead comment

## Done Criteria

- [ ] `tests/Unit/HardwareUpdatedEventTest.php` exists with ≥2 test cases
- [ ] `tests/Feature/HardwareGisCacheListenerTest.php` exists with ≥2 test cases
- [ ] `covers(HardwareUpdated::class)` annotation present in unit test
- [ ] `covers(HardwareGisCacheListener::class)` annotation present in feature test
- [ ] All tests in both files pass: `composer test -- tests/Unit/HardwareUpdatedEventTest.php tests/Feature/HardwareGisCacheListenerTest.php`
- [ ] Full test suite passes: `composer test`
- [ ] PHPStan clean: `composer phpstan`
- [ ] Pint formatted: `composer pint`
