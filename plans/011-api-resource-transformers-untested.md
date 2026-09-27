# Plan 11: NotificationResource + TodoResource API transformers untested — Flutter integration risk

> Written against commit: `7e0052e` (beta/sydney)
> Category: Test | Effort: S | Impact: HIGH

## Problem

`NotificationResource` and `TodoResource` are the sole API response transformers for two Flutter-consumed endpoints (`/api/notifications`, `/api/todos`). Both have **zero dedicated test coverage**. A breaking change to field names, types, or nullability would silently corrupt the Flutter contract with no CI signal.

### Evidence

- `app/Http/Resources/NotificationResource.php:27-45` — 11 fields transformed including ISO date casting (`read_at?->toISOString()`, `created_at?->toISOString()`) and `data` array passthrough
- `app/Http/Resources/TodoResource.php:10-26` — 9 fields including `whenLoaded('unit')` conditional relation with sub-fields `id` and `name`
- `tests/Feature/NotificationApiTest.php:16` — `covers(NotificationController::class)` — does NOT cover `NotificationResource`
- `tests/Feature/TodoApiTest.php:16` — `covers(TodoController::class)` — does NOT cover `TodoResource`
- `search_files` for `NotificationResource|TodoResource` in `tests/` returns zero matches — no test references either resource class

### Why this matters

1. **NotificationResource** casts `read_at` and `created_at` to `?->toISOString()`. If the cast is removed or a field renamed, the Flutter app receives `null` or a different date format — silently broken notification timestamps.
2. **TodoResource** uses `whenLoaded('unit')` for the conditional `unit` sub-object. If the eager-load in the controller is removed, `unit` silently becomes `null` in the response. The existing `TodoApiTest` asserts on `data.*.id` and `data.*.title` but never verifies the `unit` sub-object structure.
3. **No other test file** in the project tests a Resource class directly — this is a systemic gap, but these two are the highest-risk because they serve the Flutter API.

## Solution

Create two Pest test files: `tests/Feature/NotificationResourceTest.php` and `tests/Feature/TodoResourceTest.php`. Each instantiates the resource directly against a model and asserts the exact JSON shape the Flutter app expects.

### Before (does not exist)

No resource test files.

### After — NotificationResourceTest

```php
<?php

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

covers(NotificationResource::class);

uses(TestCase::class, RefreshDatabase::class);

it('transforms all expected fields', function () {
    $notification = Notification::factory()->create([
        'type' => 'ticket',
        'title' => 'New ticket assigned',
        'body' => 'You have a new ticket',
        'icon' => 'ticket-icon',
        'color' => '#ff0000',
        'url' => '/tickets/1',
        'data' => ['ticket_id' => 1],
        'is_read' => false,
        'read_at' => null,
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request());

    expect($array)->toHaveKeys([
        'id', 'type', 'title', 'body', 'icon', 'color', 'url', 'data',
        'is_read', 'read_at', 'created_at',
    ]);
    expect($array['type'])->toBe('ticket');
    expect($array['title'])->toBe('New ticket assigned');
    expect($array['is_read'])->toBeFalse();
    expect($array['read_at'])->toBeNull();
});

it('casts read_at to ISO string when present', function () {
    $notification = Notification::factory()->create([
        'read_at' => now(),
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request());

    expect($array['read_at'])->toBeString();
    // Must be valid ISO 8601
    expect(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $array['read_at']))->not->toBeFalse();
});

it('casts created_at to ISO string', function () {
    $notification = Notification::factory()->create();

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request());

    expect($array['created_at'])->toBeString();
    expect(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $array['created_at']))->not->toBeFalse();
});

it('passes through data array', function () {
    $notification = Notification::factory()->create([
        'data' => ['custom_key' => 'custom_value', 'nested' => ['a' => 1]],
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request());

    expect($array['data'])->toBe(['custom_key' => 'custom_value', 'nested' => ['a' => 1]]);
});

it('returns null for nullable fields when not set', function () {
    $notification = Notification::factory()->create([
        'icon' => null,
        'color' => null,
        'url' => null,
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request());

    expect($array['icon'])->toBeNull();
    expect($array['color'])->toBeNull();
    expect($array['url'])->toBeNull();
});
```

### After — TodoResourceTest

```php
<?php

use App\Http\Resources\TodoResource;
use App\Models\Todo;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

covers(TodoResource::class);

uses(TestCase::class, RefreshDatabase::class);

it('transforms all expected fields without unit relation', function () {
    $todo = Todo::factory()->create([
        'title' => 'Review lab results',
        'start_at' => now(),
        'end_at' => now()->addDay(),
        'is_completed' => false,
    ]);

    $resource = new TodoResource($todo);
    $array = $resource->toArray(new Request());

    expect($array)->toHaveKeys([
        'id', 'title', 'start_at', 'end_at', 'is_completed', 'unit_id', 'unit',
        'created_at', 'updated_at',
    ]);
    expect($array['title'])->toBe('Review lab results');
    expect($array['is_completed'])->toBeFalse();
    // unit not loaded → must be absent from output
    expect($array)->not->toHaveKey('unit');
});

it('includes unit sub-object when relation is loaded', function () {
    $unit = Unit::create(['name' => 'Radiology']);
    $todo = Todo::factory()->create(['unit_id' => $unit->id]);

    $resource = new TodoResource($todo->load('unit:id,name'));
    $array = $resource->toArray(new Request());

    expect($array['unit'])->toBe(['id' => $unit->id, 'name' => 'Radiology']);
});

it('returns null unit_id when todo has no unit', function () {
    $todo = Todo::factory()->create(['unit_id' => null]);

    $resource = new TodoResource($todo);
    $array = $resource->toArray(new Request());

    expect($array['unit_id'])->toBeNull();
    expect($array)->not->toHaveKey('unit');
});

it('casts is_completed to boolean', function () {
    $todo = Todo::factory()->create(['is_completed' => true]);

    $resource = new TodoResource($todo);
    $array = $resource->toArray(new Request());

    expect($array['is_completed'])->toBeTrue();
});
```

## Files in Scope

- `tests/Feature/NotificationResourceTest.php` (new)
- `tests/Feature/TodoResourceTest.php` (new)

## Files Out of Scope

- `app/Http/Resources/NotificationResource.php` (no changes — resource is correct)
- `app/Http/Resources/TodoResource.php` (no changes — resource is correct)
- `app/Http/Controllers/Api/NotificationController.php` (no changes)
- `app/Http/Controllers/Api/TodoController.php` (no changes)
- Existing `NotificationApiTest.php` and `TodoApiTest.php` (no changes to their `covers()` annotations — separate plan 012 addresses wrong covers)

## Steps

### Step 1: Create NotificationResourceTest
1. Create `tests/Feature/NotificationResourceTest.php` with 5 test cases (all fields, ISO date casting for `read_at`, ISO date casting for `created_at`, `data` passthrough, nullable fields)
2. Ensure `covers(NotificationResource::class)` annotation is present
3. Verify: `grep "covers(NotificationResource" tests/Feature/NotificationResourceTest.php` returns a match

### Step 2: Create TodoResourceTest
1. Create `tests/Feature/TodoResourceTest.php` with 4 test cases (fields without unit, unit sub-object when loaded, null unit_id, boolean cast)
2. Ensure `covers(TodoResource::class)` annotation is present
3. Verify: `grep "covers(TodoResource" tests/Feature/TodoResourceTest.php` returns a match

### Step 3: Run the new tests
1. `composer test -- tests/Feature/NotificationResourceTest.php` — all tests pass
2. `composer test -- tests/Feature/TodoResourceTest.php` — all tests pass

### Step 4: Verify quality gates
1. `composer test` — full suite (1352+ tests) still passes
2. `composer phpstan` — no new errors
3. `composer pint` — format the new files

### Step 5: Commit and push
1. `git add tests/Feature/NotificationResourceTest.php tests/Feature/TodoResourceTest.php`
2. `git commit -m "test(NotificationResource+TodoResource): add transformer unit tests for Flutter API contract"`
3. `git push`

## Test Plan

1. Run `composer test -- tests/Feature/NotificationResourceTest.php` — 5 tests pass
2. Run `composer test -- tests/Feature/TodoResourceTest.php` — 4 tests pass
3. Verify tests are meaningful: temporarily remove `'data' => $model->data` from NotificationResource, confirm `it('passes through data array')` fails
4. Verify tests are meaningful: temporarily change `'name' => $this->unit->name` to `'unit_name' => $this->unit->name` in TodoResource, confirm `it('includes unit sub-object when relation is loaded')` fails
5. Run full suite: `composer test` — all tests pass
6. Run `composer phpstan` — no new errors
7. Run `composer pint` — no formatting issues

## Maintenance Note

- If a field is added to `NotificationResource` or `TodoResource`, a corresponding test assertion must be added here. The `toHaveKeys` assertion will fail on new fields, making this self-enforcing.
- The `covers()` annotation links these tests to the resource classes in coverage reports — ensure it stays accurate if classes are renamed or moved.
- The TodoResource tests use `Todo::factory()` while NotificationResource tests use `Notification::factory()`. If factories change, update the tests accordingly.
- These tests run at the Resource (unit) level, not the HTTP level — they do not require auth setup, making them fast and isolated.

## Done Criteria

- [ ] `tests/Feature/NotificationResourceTest.php` exists with ≥5 test cases
- [ ] `tests/Feature/TodoResourceTest.php` exists with ≥4 test cases
- [ ] `covers(NotificationResource::class)` and `covers(TodoResource::class)` annotations present
- [ ] All new tests pass: `composer test -- tests/Feature/NotificationResourceTest.php tests/Feature/TodoResourceTest.php`
- [ ] Full test suite passes: `composer test`
- [ ] PHPStan clean: `composer phpstan`
- [ ] Pint formatted: `composer pint`
- [ ] Verify: `grep -rn "covers(NotificationResource\|covers(TodoResource" tests/` returns exactly 2 matches
