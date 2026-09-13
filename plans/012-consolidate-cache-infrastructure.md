# Plan 012: Consolidate cache infrastructure + fix PruneStaleCache + Cache::increment race

- **Category:** tech-debt
- **Effort:** M
- **Risk:** MED
- **Priority:** P2
- **Depends on:** none
- **Status:** proposed

## ⚠️ TL;DR فارسی

**مشکل:** کلید کش ۱۵ بار تکرار. Namespace ۴ فایل hardcoded. PruneStaleCache بی‌فایده.

**⚠️ نکته:** تغییر فرمت کلید = cold cache spike.

**ریسک:** 🟡 متوسط


## Problem

The cache versioning system has a well-designed `CacheInvalidationService` with `remember()` and `versioned` key construction, but most consumers bypass it — constructing keys manually with raw `Cache::get('namespace_version', 0)`. Additionally, the cache pruning command is a no-op, and cache namespace names are duplicated across 4+ files.

### Issue 1: Raw `Cache::get('xxx_version', 0)` in views (11 occurrences)

Views manually construct versioned cache keys instead of using the service:

| File | Line | Raw Call |
|------|------|----------|
| `livewire/dashboard.blade.php` | 43, 176, 198, 214 | `Cache::get('dashboard_version', 0)` (4×) |
| `livewire/hr/dashboard.blade.php` | 32 | `Cache::get('hr_stats_version', 0)` |
| `livewire/maps/county.blade.php` | 27 | `Cache::get('maps_version', 0)` |
| `livewire/maps/point.blade.php` | 27 | `Cache::get('maps_version', 0)` |
| `livewire/maps/unit.blade.php` | 33, 68 | `Cache::get('maps_version', 0)` (2×) |
| `livewire/maps/interactive.blade.php` | 22 | `Cache::get('maps_version', 0)` |
| `livewire/todo/todo.blade.php` | 49 | `Cache::get('calendar_version', 0)` |

### Issue 2: Cache namespace lists hardcoded in 4 files

Namespace names are duplicated in:
1. **`app/Console/Commands/PruneStaleCache.php`** (line 18) — array of 10 namespaces
2. **`app/Providers/AppServiceProvider.php`** (lines 81, 86) — `report_todos`, `report_tickets`, `gis`, `calendar`, `dashboard`
3. **`app/Models/Hardware.php`** (lines 84-87) — `hardware_stats`, `gis`, `maps`, `dashboard`
4. **`app/Models/Person.php`** (line 48) — `hr_stats`, `dashboard`, `maps`, `gis`

If a new namespace is added, it must be updated in all 4 places.

### Issue 3: `PruneStaleCache` is a no-op

```php
// PruneStaleCache.php lines 21-36
foreach ($namespaces as $namespace) {
    $currentVersion = $cache->getVersion($namespace);
    $threshold = $currentVersion - 10;
    // Redis-specific pruning using tags would be ideal, but we
    // preserve backward compatibility by relying on TTL expiry.
    $this->line("  [{$namespace}] current version: {$currentVersion}");
}
// ... just logs and returns
```

The command is scheduled hourly but never actually prunes anything. It logs version numbers and says "stale keys will expire via TTL."

### Issue 4: `Cache::increment()` race condition with file driver

`CacheInvalidationService::increment()` calls `Cache::increment("{$namespace}_version")`. With the file driver, `Cache::increment()` is not atomic — two concurrent requests can read the same value and both increment to the same result. With Redis, it's atomic.

Currently the scheduler forces `CACHE_STORE=array` in tests, and production uses Redis, but the code doesn't document this requirement or guard against the file driver.

## Proposed Fix

### Step 1: Define namespace constants in `CacheInvalidationService`

```php
// In CacheInvalidationService.php — add namespace constants
class CacheInvalidationService implements CacheInvalidationServiceInterface
{
    public const NAMESPACES = [
        'hardware_stats',
        'gis',
        'maps',
        'dashboard',
        'hr_stats',
        'report_todos',
        'report_tickets',
        'report_units',
        'unit_hierarchy',
        'calendar',
    ];

    // Convenience constants for commonly referenced namespaces
    public const NS_HARDWARE_STATS = 'hardware_stats';
    public const NS_GIS = 'gis';
    public const NS_MAPS = 'maps';
    public const NS_DASHBOARD = 'dashboard';
    public const NS_HR_STATS = 'hr_stats';
    public const NS_REPORT_TODOS = 'report_todos';
    public const NS_REPORT_TICKETS = 'report_tickets';
    public const NS_REPORT_UNITS = 'report_units';
    public const NS_UNIT_HIERARCHY = 'unit_hierarchy';
    public const NS_CALENDAR = 'calendar';

    public const NAMESPACES_WITH_MAPS = [self::NS_MAPS, self::NS_GIS, self::NS_HARDWARE_STATS, self::NS_DASHBOARD];
    public const NAMESPACES_WITH_PERSON = [self::NS_HR_STATS, self::NS_DASHBOARD, self::NS_MAPS, self::NS_GIS];
    // ... etc for other common invalidation groups
```

### Step 2: Update consumers to use constants

Replace hardcoded strings:

```php
// Hardware::flushStatsCache() — before
$cache->increment('hardware_stats');
$cache->increment('gis');
$cache->increment('maps');
$cache->increment('dashboard');

// After
use App\Services\CacheInvalidationService;
$cache->increment(CacheInvalidationService::NS_HARDWARE_STATS);
$cache->increment(CacheInvalidationService::NS_GIS);
$cache->increment(CacheInvalidationService::NS_MAPS);
$cache->increment(CacheInvalidationService::NS_DASHBOARD);
```

```php
// Person boot() — before
foreach (['hr_stats', 'dashboard', 'maps', 'gis'] as $namespace) {

// After
foreach (CacheInvalidationService::NAMESPACES_WITH_PERSON as $namespace) {
```

```php
// PruneStaleCache — before
$namespaces = ['hardware_stats', 'gis', 'maps', ...];

// After
$namespaces = CacheInvalidationService::NAMESPACES;
```

### Step 3: Replace raw `Cache::get` in views with service calls

Replace each raw version access with the service:

```php
// Before (dashboard.blade.php:43)
$v = Cache::get('dashboard_version', 0);

// After
$v = app(CacheInvalidationServiceInterface::class)->getVersion('dashboard');
```

For cache key construction in views, use `cacheKey()`:

```php
// Before (maps/county.blade.php:27)
Cache::remember('county:regions_with_boundaries:v' . Cache::get('maps_version', 0) . ':' . $hash, 300, ...);

// After
$cacheService = app(CacheInvalidationServiceInterface::class);
$key = $cacheService->cacheKey('maps', $scopeHash, 'regions');
Cache::remember($key, 300, fn () => ...);
```

For components that already use `Cache::remember()` with manual versioning, refactor to use the service's `remember()` method:

```php
// Before (dashboard.blade.php:46)
$globalStats = Cache::remember("dashboard:global:v{$v}", 300, function () { ... });

// After
$globalStats = app(CacheInvalidationServiceInterface::class)
    ->remember('dashboard', $scopeKey, fn () => [...], 5, ['type' => 'global']);
```

### Step 4: Fix `PruneStaleCache` to actually work (or remove it)

Two options:

**Option A: Remove the command** (recommended if TTL-based expiry is acceptable)
- Remove the command file
- Remove the hourly schedule entry
- Document that stale keys expire via TTL (already the behavior)

**Option B: Make it actually prune** (if we switch to Redis tags)
- Use `Cache::tags(["ns:{$namespace}"])->flush()` for Redis driver
- Add a guard: `$this->warn('Pruning requires Redis driver. Skipping.')` for other drivers
- This requires changing all `Cache::remember()` calls to use tags

**Recommendation:** Go with Option A. The current TTL-based expiry already works correctly. The command adds confusion by appearing to do something when it doesn't. Remove it and add a comment in the service explaining the TTL-based approach.

### Step 5: Document Redis requirement

Add to `CacheInvalidationServiceInterface`:

```php
/**
 * IMPORTANT: This service uses Cache::increment() for atomic version bumping.
 * This is only safe with drivers that support atomic increment (Redis, Memcached).
 * The file and array drivers are NOT atomic and may cause race conditions.
 *
 * Production MUST use Redis. The file driver is acceptable for local development.
 */
```

Also add to `AGENTS.md` under Cache Version Namespaces:

```
> **Driver requirement:** `Cache::increment()` is only atomic with Redis. Production
> must use `CACHE_STORE=redis`. The array driver (tests) and file driver (local dev)
> are acceptable for non-concurrent usage but may have race conditions under load.
```

## Files to Create

None — all changes are modifications to existing files.

## Files to Modify

1. **`app/Services/CacheInvalidationService.php`** — add `NAMESPACES` constants and convenience arrays
2. **`app/Services/CacheInvalidationServiceInterface.php`** — add Redis requirement docblock
3. **`app/Console/Commands/PruneStaleCache.php`** — delete (or gut to remove from scheduler)
4. **`app/Console/Kernel.php`** (or `routes/console.php`) — remove `cache:prune-stale` schedule
5. **`app/Models/Hardware.php`** — use `CacheInvalidationService::NS_*` constants
6. **`app/Models/Person.php`** — use `CacheInvalidationService::NAMESPACES_WITH_PERSON`
7. **`app/Providers/AppServiceProvider.php`** — use constants for namespace lists
8. **`resources/views/livewire/dashboard.blade.php`** — replace 4× `Cache::get('dashboard_version')` with service
9. **`resources/views/livewire/hr/dashboard.blade.php`** — replace `Cache::get('hr_stats_version')` with service
10. **`resources/views/livewire/maps/county.blade.php`** — replace `Cache::get('maps_version')` with service
11. **`resources/views/livewire/maps/point.blade.php`** — replace `Cache::get('maps_version')` with service
12. **`resources/views/livewire/maps/unit.blade.php`** — replace 2× `Cache::get('maps_version')` with service
13. **`resources/views/livewire/maps/interactive.blade.php`** — replace `Cache::get('maps_version')` with service
14. **`resources/views/livewire/todo/todo.blade.php`** — replace `Cache::get('calendar_version')` with service

## Verification

1. **Grep check:** `grep -rn 'Cache::get.*_version' resources/views/` returns 0 matches.
2. **Grep check:** `grep -rn "'hardware_stats'" app/` only appears in `CacheInvalidationService.php` (the single source of truth).
3. **Cache behavior:** All pages still cache correctly — navigate to dashboard, maps, HR, todo. Verify cached data loads (check DevTools network tab for consistent responses).
4. **Version invalidation:** Create/update/delete a Person → verify HR dashboard cache refreshes.
5. **PruneStaleCache removed:** `php artisan list | grep prune` returns nothing. Scheduler no longer references it.
6. **Test pass:** `composer test` — no regressions.

## Risks

- **Risk:** Removing `PruneStaleCache` may cause stale keys to accumulate with Redis. **Mitigation:** TTL-based expiry already handles this. Keys have 2-5 minute TTLs. The version bump makes old keys unreachable; TTL cleans them up. With Redis, memory is managed externally.
- **Risk:** Replacing 14 view files with service calls is a large diff. **Mitigation:** Each replacement is mechanical — one line per `Cache::get(...)` call. Test each page individually.
- **Risk:** Livewire views may not be able to resolve `CacheInvalidationServiceInterface` from the container. **Mitigation:** Livewire components run within Laravel's full container. `$this` context and `app()` helper both work. This is already proven by other service usage in views (e.g., `AccessService` in org-chart).
- **Risk:** `CacheInvalidationService` constructor has no dependencies, but consumers using `app()` must ensure it's registered. **Mitigation:** `AppServiceProvider` already binds `CacheInvalidationServiceInterface::class` to `CacheInvalidationService::class` (line 22-23).
