# Plan 016: Fix Bulk Ops Bypassing CacheInvalidationService

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

Bulk ticket operations in the inbox Livewire component use raw `Cache::increment()` calls instead of the `CacheInvalidationServiceInterface`, which means they:

1. **Miss the `dashboard_version` namespace** — the dashboard reads `Cache::get('dashboard_version', 0)` (resources/views/livewire/dashboard.blade.php:39) but the bulk ops never bump it.
2. **Use inconsistent key format** — `Cache::increment('report_tickets_version')` works but bypasses the service's `increment()` method (app/Services/CacheInvalidationService.php:15-18), which other parts of the app depend on for coherent invalidation.
3. **Skip namespaces that single-ticket Eloquent observers bump** — the `Hardware` and `Person` models use observers that call `$this->cache->increment('dashboard_version')` (tests/Feature/PersonModelTest.php:153-158), but bulk ops skip observers entirely.

### Current Code (Bug)

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php`

**Lines 254-256** (complete bulk action):
```php
Cache::increment('report_tickets_version');
Cache::increment('gis_version');
Cache::increment('calendar_version');
```

**Lines 283-285** (forward bulk action):
```php
Cache::increment('report_tickets_version');
Cache::increment('gis_version');
Cache::increment('calendar_version');
```

### Related: CacheInvalidationService

**File:** `app/Services/CacheInvalidationService.php:15-18`
```php
public function increment(string $namespace): int
{
    return Cache::increment("{$namespace}_version");
}
```

The service prefixes `_version` automatically. The raw calls hardcode `report_tickets_version` which happens to match but `dashboard_version` is never bumped.

### PruneStaleCache Namespaces

**File:** `app/Console/Commands/PruneStaleCache.php:18`
```php
$namespaces = ['hardware_stats', 'gis', 'maps', 'dashboard', 'hr_stats', 'report_todos', 'report_tickets', 'report_units', 'unit_hierarchy', 'calendar'];
```

The dashboard reads `Cache::get('dashboard_version', 0)` — this key lives outside the versioned cache key pattern but must still be bumped when data changes.

---

## Solution

Replace all `Cache::increment()` calls in both bulk action paths with calls to `CacheInvalidationServiceInterface::increment()` for the correct namespaces. Also bump `dashboard` since bulk ticket status changes affect dashboard stats.

### Changes

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php`

Replace lines 254-256 (complete action) with:
```php
$cache = app(CacheInvalidationServiceInterface::class);
$cache->increment('report_tickets');
$cache->increment('gis');
$cache->increment('calendar');
$cache->increment('dashboard');
```

Replace lines 283-285 (forward action) with the same block.

Add import at top of file:
```php
use App\Services\CacheInvalidationServiceInterface;
```

### Why `dashboard` Must Be Bumped

The dashboard component (`resources/views/livewire/dashboard.blade.php:39`) reads:
```php
$v = Cache::get('dashboard_version', 0);
```

And uses it to build cache keys like `"dashboard:stats:v{$v}:{$scopeKey}"`. If `dashboard_version` isn't bumped, stale ticket counts persist on the dashboard until TTL expiry.

### Why `PruneStaleCache` Doesn't Need Changes

`PruneStaleCache` (app/Console/Commands/PruneStaleCache.php:18) already lists `report_tickets` and `calendar` as namespaces. It does NOT list `dashboard` as a "stale key pruner" namespace — but `dashboard_version` is a simple counter, not a versioned key. Adding `dashboard` to `PruneStaleCache::$namespaces` is unnecessary because that command only logs version numbers; it doesn't prune (line 34: "Stale keys will expire via TTL").

---

## Verification

1. **Run existing tests:**
   ```bash
   composer test -- --filter=Ticket
   ```
   Expected: all ticket-related tests pass (no regression).

2. **Manual smoke test:**
   - Open the inbox page → select multiple tickets → click "complete"
   - Immediately navigate to the dashboard
   - Verify ticket counts reflect the completion (dashboard stats updated)

3. **Grep for remaining raw calls:**
   ```bash
   grep -rn 'Cache::increment' resources/views/
   ```
   Expected: 0 matches (all moved to service calls).

---

## STOP Conditions

- If `CacheInvalidationServiceInterface` is not bound in the container (check `AppServiceProvider`), abort and investigate.
- If any existing test for inbox bulk ops fails after the change, revert and investigate.

---

## Out of Scope

- Changing the dashboard's `Cache::get('dashboard_version')` pattern to use the versioned key pattern (that's a separate refactor).
- Modifying PruneStaleCache to actively prune old dashboard keys.
- Changing single-ticket Eloquent observer invalidation.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test -- --filter=inbox` | All inbox tests pass |
| 2 | `composer test -- --filter=dashboard` | Dashboard tests pass |
| 3 | `grep -rn 'Cache::increment' resources/views/` | 0 matches |
| 4 | `vendor/bin/pint --dirty --format agent` | No changes needed |

---

## Maintenance Notes

- **Coding convention:** This project uses `CacheInvalidationServiceInterface` (injected via `app()`) everywhere except these two spots. Fix brings inbox in line.
- **Cache invalidation pattern:** `->increment('namespace')` bumps `{namespace}_version`. All consumers that `Cache::remember(...)` with a `v{$v}` key automatically pick up the new version.
- **Future:** Consider extracting cache invalidation into an event/listener so Livewire components never touch the cache service directly.
