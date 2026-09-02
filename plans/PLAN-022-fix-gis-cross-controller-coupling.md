# Plan 022: Fix GisController Cross-Controller Coupling

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

`HardwareController` directly calls `GisController::invalidateCache()` in 5 places, creating tight coupling between two unrelated controllers. The static method pattern makes this hard to test and violates the Dependency Inversion Principle.

### Current Code (Coupling)

**File:** `app/Http/Controllers/Api/HardwareController.php`

```php
// Line 188 (store method):
GisController::invalidateCache();

// Line 225 (update method):
GisController::invalidateCache();

// Line 238 (destroy method):
GisController::invalidateCache();

// Line 316 (bulkDelete method):
app(GisController::class)::invalidateCache();

// Line 356 (bulkRestore method):
app(GisController::class)::invalidateCache();
```

Note the inconsistency: lines 188, 225, 238 use `GisController::invalidateCache()` (direct static call), while lines 316, 356 use `app(GisController::class)::invalidateCache()` (resolving via container then calling static).

### GisController::invalidateCache Implementation

**File:** `app/Http/Controllers/Api/GisController.php:421-424`

```php
public static function invalidateCache(): void
{
    app(CacheInvalidationServiceInterface::class)->increment('gis');
}
```

The method simply bumps the `gis` version counter. It doesn't use any GisController instance state.

---

## Solution

Dispatch a `HardwareUpdated` event from `HardwareController` and create a `HardwareGisCacheListener` that invalidates the GIS cache. This removes the direct coupling.

### New Files

**1. `app/Events/HardwareUpdated.php`**

```php
<?php

namespace App\Events;

use App\Models\Hardware;
use Illuminate\Foundation\Events\Dispatchable;

class HardwareUpdated
{
    use Dispatchable;

    public function __construct(
        public Hardware $hardware,
        public string $action, // 'created', 'updated', 'deleted', 'bulk_deleted', 'bulk_restored'
    ) {}
}
```

**2. `app/Listeners/HardwareGisCacheListener.php`**

```php
<?php

namespace App\Listeners;

use App\Events\HardwareUpdated;
use App\Services\CacheInvalidationServiceInterface;

class HardwareGisCacheListener
{
    public function __construct(
        protected CacheInvalidationServiceInterface $cache
    ) {}

    public function handle(HardwareUpdated $event): void
    {
        $this->cache->increment('gis');
    }
}
```

### Register Event-Listener

**File:** `app/Providers/EventServiceProvider.php` (or `app/Providers/AppServiceProvider.php` if no EventServiceProvider exists)

```php
protected $listen = [
    \App\Events\HardwareUpdated::class => [
        \App\Listeners\HardwareGisCacheListener::class,
    ],
];
```

**Alternative (Laravel 13 — events auto-discovered):** In Laravel 13, if event/listener auto-discovery is enabled, no manual registration is needed. Check `config/event.php` for `'discover' => true`. If not, register in `EventServiceProvider`.

### HardwareController Changes

**File:** `app/Http/Controllers/Api/HardwareController.php`

Replace all 5 `GisController::invalidateCache()` calls with:
```php
use App\Events\HardwareUpdated;

// In store() method:
event(new HardwareUpdated($hardware, 'created'));

// In update() method:
event(new HardwareUpdated($hardware, 'updated'));

// In destroy() method:
event(new HardwareUpdated($hardware, 'deleted'));

// In bulkDelete() method:
event(new HardwareUpdated($hardware, 'bulk_deleted')); // where $hardware is any model instance

// In bulkRestore() method:
event(new HardwareUpdated($hardware, 'bulk_restored'));
```

Remove `use App\Http\Controllers\Api\GisController;` import.

---

## Verification

1. **Run all tests:**
   ```bash
   composer test
   ```
   Expected: all 928 tests pass.

2. **Verify HardwareController no longer imports GisController:**
   ```bash
   grep -n 'GisController' app/Http/Controllers/Api/HardwareController.php
   ```
   Expected: 0 matches.

3. **Manual smoke test:**
   - Create/update/delete hardware via API
   - Navigate to GIS map page
   - Verify map data reflects the change (cache was invalidated)

4. **Check event is dispatched:**
   ```bash
   php artisan tinker --execute '
   Event::fake();
   $hw = \App\Models\Hardware::first();
   event(new \App\Events\HardwareUpdated($hw, "updated"));
   Event::assertDispatched(\App\Events\HardwareUpdated::class);
   '
   ```

---

## STOP Conditions

- If `EventServiceProvider` doesn't exist and auto-discovery isn't enabled, create one.
- If any test directly asserts `GisController::invalidateCache()` was called, update the assertion to check for `HardwareUpdated` event dispatch.
- If the `bulkDelete` method doesn't have a Hardware model instance, create a dummy or dispatch without model.

---

## Out of Scope

- Adding more events for other cache invalidation (e.g., `TicketUpdated`, `PersonUpdated`).
- Converting all static `invalidateCache()` methods in `GisController` to event-driven (separate plan).
- Changing `CacheInvalidationService` itself.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test` | 928 tests pass |
| 2 | `grep -n 'GisController' app/Http/Controllers/Api/HardwareController.php` | 0 matches |
| 3 | Hardware CRUD → GIS map refreshes | Cache invalidated |
| 4 | `vendor/bin/pint --dirty --format agent` | Clean |

---

## Maintenance Notes

- **Event naming:** `HardwareUpdated` is used generically even for creates/deletes — this is a common Laravel convention. If the team prefers `HardwareChanged` or `HardwareModified`, rename accordingly.
- **Future events:** Consider adding `TicketUpdated`, `PersonUpdated`, `UnitUpdated` for similar decoupling across all controllers that call `invalidateCache()`.
- **Testing events:** Use `Event::fake()` in tests to assert events are dispatched without triggering listeners.
