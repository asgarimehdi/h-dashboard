# Plan 016: Cache invalidation storm on every Hardware/Person save — batch & deduplicate

> Written against commit: `d7434fd` (beta/sydney)
> Category: Performance | Effort: M | Impact: Medium

## Problem

Every Hardware or Person save triggers **4 synchronous `Cache::increment()` calls**, bumping version counters for multiple cache namespaces. In a write-heavy scenario (bulk imports, batch updates), this creates:

1. **Thundering herd**: Many concurrent readers see the old version, miss cache, and simultaneously rebuild expensive queries (hardware stats, GIS, dashboard, HR stats).
2. **Redundant increments**: A single bulk operation triggers `flushStatsCache()` from both the Eloquent `saved` event AND an explicit call in the controller (lines 311, 350 of `HardwareController.php`), doubling invalidation traffic.
3. **Over-broad invalidation**: Saving a Person bumps `hr_stats`, `dashboard`, `maps`, `gis` — but `maps` and `gis` only change if the person's unit assignment changes. Minor metadata edits (name, hire date) needlessly bust unrelated caches.
4. **Double `gis` increment on Hardware write**: `Hardware::flushStatsCache()` bumps `gis` (line 85), and then `HardwareUpdated` event dispatches `HardwareGisCacheListener` which bumps `gis` again (line 16).

### Evidence

- `app/Models/Hardware.php:66-88` — `saved`/`deleted` events call `flushStatsCache()` → 4 increments (`hardware_stats`, `gis`, `maps`, `dashboard`)
- `app/Models/Person.php:45-52` — `saved`/`deleted` events loop through 4 namespaces (`hr_stats`, `dashboard`, `maps`, `gis`)
- `app/Http/Controllers/Api/HardwareController.php:311` — `bulkMark` calls `flushStatsCache()` explicitly, but the Eloquent `saved` event on each hardware row already called it
- `app/Http/Controllers/Api/HardwareController.php:350` — `bulkDelete` calls `flushStatsCache()` explicitly, same duplication
- `app/Listeners/HardwareGisCacheListener.php:16` — bumps `gis` again after `flushStatsCache()` already bumped it
- `app/Providers/AppServiceProvider.php:73-95` — Todo (2), Ticket (4), Unit (4) invalidations all happen inline on model events

**Namespace increment frequency (per model save):**

| Model | Namespaces bumped | Duplicate increments |
|-------|-------------------|---------------------|
| Hardware (normal) | 4 + 1 (listener) = 5 | `gis` bumped twice |
| Hardware (bulk) | 4 × 2 (event + explicit) + 1 | `gis` × 2, all × 2 |
| Person | 4 | None |
| Unit | 4 | None |
| Todo | 2 | None |
| Ticket | 4 | None |

## Solution

Add a **request-scoped batch buffer** to `CacheInvalidationService` that collects namespace increment requests and flushes them once (at request end or on explicit flush). This eliminates duplicate increments and allows callers to deduplicate.

### Step 1: Add batch buffering to `CacheInvalidationService`

**Before** (`app/Services/CacheInvalidationService.php`):
```php
public function increment(string $namespace): int
{
    return Cache::increment("{$namespace}_version");
}
```

**After:**
```php
/** @var array<string, true> */
private array $pending = [];

public function increment(string $namespace): int
{
    if (! empty($this->pending)) {
        $this->pending[$namespace] = true;

        return $this->getVersion($namespace);
    }

    return Cache::increment("{$namespace}_version");
}

public function flushPending(): int
{
    $count = count($this->pending);
    foreach (array_keys($this->pending) as $ns) {
        Cache::increment("{$ns}_version");
    }
    $this->pending = [];

    return $count;
}

public function batch(\Closure $callback): mixed
{
    $this->pending = [];
    $result = $callback();
    $this->flushPending();

    return $result;
}
```

**Update the interface** (`app/Services/CacheInvalidationServiceInterface.php`):
```php
public function flushPending(): int;
public function batch(\Closure $callback): mixed;
```

### Step 2: Wrap bulk operations in `HardwareController`

**Before** (`HardwareController::bulkMark`):
```php
event(new HardwareUpdated($hardwares->first(), 'bulk_mark'));
Hardware::flushStatsCache();
```

**After:**
```php
event(new HardwareUpdated($hardwares->first(), 'bulk_mark'));
// flushStatsCache() is called by the Eloquent `saved` event on each row.
// No explicit call needed — the request-scoped buffer deduplicates.
```

Remove both explicit `Hardware::flushStatsCache()` calls from `bulkMark` (line 311) and `bulkDelete` (line 350). The Eloquent `saved`/`deleted` events already handle it.

### Step 3: Wrap model event invalidations in `AppServiceProvider`

Wrap the Todo, Ticket, and Unit observer callbacks in `$cache->batch()`:

**Before** (`app/Providers/AppServiceProvider.php`):
```php
Todo::created(fn () => $invalidate($todoNamespaces));
```

**After:**
```php
Todo::created(function () use ($cache, $todoNamespaces) {
    $cache->batch(fn () => $invalidate($todoNamespaces));
});
```

Or simpler: make `$invalidate` call `$cache->batch()` internally.

### Step 4: Remove duplicate `gis` increment from `HardwareGisCacheListener`

Since `Hardware::flushStatsCache()` already bumps `gis`, the listener is redundant. Two options:

- **Option A (minimal):** Remove `gis` from `flushStatsCache()` and let the listener handle it → no change in behavior, just moves the bump to the event.
- **Option B (preferred):** Remove the listener entirely and keep `gis` in `flushStatsCache()` — fewer moving parts.

I recommend **Option B**: delete `HardwareGisCacheListener`, remove it from `EventServiceProvider::$listen`, and keep `gis` in `flushStatsCache()`.

### Step 5: Optimize Person cache invalidation

Not all Person saves need all 4 namespaces. Split into conditional invalidation:

```php
// In Person::boot()
foreach (['saved', 'deleted'] as $event) {
    static::$event(function ($model) {
        $cache = app(CacheInvalidationServiceInterface::class);
        $cache->increment('hr_stats');        // Always — HR stats depend on person
        $cache->increment('dashboard');       // Always — dashboard counts persons

        if ($model->isDirty('u_id')) {
            // Only bump maps/gis if the person moved to a different unit
            $cache->increment('maps');
            $cache->increment('gis');
        }
    });
}
```

**Note:** `deleted` events have no dirty attributes, so `isDirty()` is always false on delete. Use `$model->wasChanged('u_id')` for `saved` events or handle the `deleted` case separately.

## Files in Scope

- `app/Services/CacheInvalidationService.php`
- `app/Services/CacheInvalidationServiceInterface.php`
- `app/Models/Hardware.php`
- `app/Models/Person.php`
- `app/Http/Controllers/Api/HardwareController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Listeners/HardwareGisCacheListener.php` (delete)
- `app/Providers/EventServiceProvider.php` (remove listener)
- `app/Events/HardwareUpdated.php` (unchanged, still dispatched for other listeners)

## Files Out of Scope

- `app/Console/Commands/PruneStaleCache.php` (just reads versions, no change needed)
- `tests/Unit/CacheInvalidationServiceTest.php` (update only to test `batch()`)
- `tests/Feature/CacheInvalidationTest.php` (update assertions)
- `tests/Feature/HardwareModelTest.php` (update `flushStatsCache` tests)

## Steps

### Step 1: Add batch support to `CacheInvalidationService`
1. Add `$pending` array, modify `increment()` to buffer when batched
2. Add `flushPending()` and `batch()` methods
3. Update `CacheInvalidationServiceInterface` with new method signatures
4. Verify: `composer phpstan`

### Step 2: Remove redundant `flushStatsCache()` from `HardwareController`
1. Delete `Hardware::flushStatsCache();` at line 311 (bulkMark)
2. Delete `Hardware::flushStatsCache();` at line 350 (bulkDelete)
3. Verify: `grep -rn "flushStatsCache" app/Http/` returns empty

### Step 3: Wrap AppServiceProvider invalidations in batch
1. Modify `$invalidate` closure to call `$cache->batch()` or buffer internally
2. Verify: `composer phpstan`

### Step 4: Remove `HardwareGisCacheListener`
1. Delete `app/Listeners/HardwareGisCacheListener.php`
2. Remove `HardwareUpdated::class => [HardwareGisCacheListener::class]` from `EventServiceProvider::$listen`
3. Verify: `php artisan event:list | grep HardwareUpdated` shows no listeners (only direct dispatches)

### Step 5: Optimize Person model invalidation
1. Add `$model->isDirty('u_id')` conditional for `maps`/`gis` bumps
2. Handle `deleted` event (no dirty check — always bump all 4 on delete since unit membership is being removed)
3. Verify: `composer phpstan`

### Step 6: Update tests
1. Update `CacheInvalidationServiceTest` — add tests for `batch()` and `flushPending()`
2. Update `CacheInvalidationTest` — assert that double writes only increment version by 1 (not 2)
3. Update `HardwareModelTest::test_flush_stats_cache_*` tests
4. Verify: `composer test`

### Step 7: Format & final check
1. `composer pint`
2. `composer phpstan`
3. `composer test` — all 1352+ tests pass

## Test Plan

1. **Unit test `batch()`**: Call `batch()` with 3 increments inside, verify each namespace incremented exactly once
2. **Unit test double-increment dedup**: Call `increment('x')` twice inside `batch()`, verify version bumped once
3. **Unit test `flushPending()` returns count**: Verify return value matches number of distinct namespaces
4. **Feature test no duplicate increments**: Save a Hardware, verify `gis_version` incremented exactly 1 time (was 2 before)
5. **Feature test Person conditional**: Save Person without `u_id` change → only `hr_stats` and `dashboard` bumped; save Person with `u_id` change → all 4 bumped
6. **Feature test bulkMark no double flush**: Bulk mark hardware, verify `hardware_stats_version` incremented once (was twice before)
7. **Regression**: All existing tests pass unchanged

## Maintenance Note

The `batch()` method uses a simple instance-level flag. In a queue/job context where multiple requests share a process (Octane), this could leak between requests. For now, the service is resolved per-request via the container singleton. If Octane is added later, ensure `flushPending()` is called in an `OperationTerminated` callback.

The `isDirty()` check in Person's `deleted` event needs special handling — `deleted` events have no dirty attributes. Use `wasChanged()` for `saved` or separate the branches.

## Done Criteria

- [ ] `CacheInvalidationService` supports `batch()` and `flushPending()`
- [ ] `CacheInvalidationServiceInterface` updated with new methods
- [ ] No explicit `flushStatsCache()` calls in `HardwareController` (removed from bulk operations)
- [ ] `HardwareGisCacheListener` deleted and removed from `EventServiceProvider`
- [ ] Person model conditionally bumps `maps`/`gis` only when `u_id` changes
- [ ] Zero duplicate namespace increments per single model save
- [ ] New unit tests for `batch()` pass
- [ ] All existing tests pass (`composer test`)
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] `grep -rn "flushStatsCache" app/Http/` returns 0 results
- [ ] `grep -rn "HardwareGisCacheListener" app/` returns 0 results
