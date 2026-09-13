# 029 — Remove Dead Code

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | LOW (Tech debt) |
| **Effort** | M |
| **Risk** | Low — removing unused code |
| **Base commit** | `a106d38` |
| **Files** | `app/Events/HardwareUpdated.php`, `app/Listeners/HardwareGisCacheListener.php`, `app/Providers/EventServiceProvider.php`, `app/Http/Controllers/Api/GisController.php`, `resources/views/livewire/hr/org-chart.blade.php` |

## Problem

Several pieces of code are dead — defined but never invoked:

### Evidence (file:line)

#### (a) `HardwareUpdated` event + `HardwareGisCacheListener` — REDUNDANT, not dead

**Correction from audit:** The `HardwareUpdated` event IS dispatched from `HardwareController.php:185,221,234,310` and the listener IS wired via `EventServiceProvider.php:17-19`. However, the listener only calls `$this->cache->increment('gis')` which is **already handled** by `Hardware::flushStatsCache()` (called on `saved`/`deleted` boot events at line 68-69 of Hardware.php, which also increments `gis`). So the event + listener is redundant (double-increment), not strictly dead. Removing it eliminates a redundant cache bump on every hardware write.

Files:
- **`app/Events/HardwareUpdated.php`** (16 lines) — only dispatched by HardwareController
- **`app/Listeners/HardwareGisCacheListener.php`** (18 lines) — only handles HardwareUpdated, calls `increment('gis')`
- **`app/Providers/EventServiceProvider.php:5-6,17-19`** — mapping + imports

#### (b) `GisController::invalidateCache()` — DEAD

**`app/Http/Controllers/Api/GisController.php:420-423`** defines `public static function invalidateCache()`. Search shows zero callers — this method is never invoked from anywhere in the codebase.

```php
public static function invalidateCache(): void
{
    app(CacheInvalidationServiceInterface::class)->increment('gis');
}
```

#### (c) `org-chart collectFirstNLevels()` — DEAD

**`resources/views/livewire/hr/org-chart.blade.php:267-277`** defines `collectFirstNLevels()`. Search shows zero callers — the component uses `expandFirstNLevels()` (line 69, 79) instead. The `expandFirstNLevels` method at line 79 does the same thing but also loads children.

```php
protected function collectFirstNLevels($nodes, int $maxLevel, int $level = 1): array
{
    $ids = [];
    foreach ($nodes as $node) {
        if ($level <= $maxLevel) {
            $ids[] = (string) $node->id;
        }
    }
    return $ids;
}
```

#### (d) Hardware scope methods — **NOT DEAD** (correction)

**`app/Models/Hardware.php:108-268`** contains 12 scope methods (`scopeFilterSearch`, `scopeFilterType`, etc.). These **ARE actively used** by `app/Http/Controllers/Api/HardwareController.php:121-132`. Do NOT remove.

## Decision

1. **Remove** `GisController::invalidateCache()` (dead, zero callers).
2. **Remove** `collectFirstNLevels()` from org-chart (dead, replaced by `expandFirstNLevels()`).
3. **Remove** `HardwareUpdated` event, `HardwareGisCacheListener`, and the EventServiceProvider mapping — they're redundant with `Hardware::flushStatsCache()`. Before removing, verify `flushStatsCache()` already calls `$cache->increment('gis')` (confirmed at Hardware.php:85).
4. **Keep** all 12 Hardware scope methods — they're used by the API controller.

## Commands

```bash
cd /home/runner/h-dashboard
# Before removing event, verify flushStatsCache covers gis
grep -n 'increment.*gis' app/Models/Hardware.php
# Should show: $cache->increment('gis');

# Run full tests after removal
composer test
vendor/bin/pint --dirty --format agent
```

## Steps

### Phase 1 — Remove `GisController::invalidateCache()`

Delete the `invalidateCache()` method from `app/Http/Controllers/Api/GisController.php` (lines 417–423):

```php
// DELETE these lines:
/**
 * Invalidate GIS cache by bumping the version counter via the unified service.
 */
public static function invalidateCache(): void
{
    app(CacheInvalidationServiceInterface::class)->increment('gis');
}
```

Also remove `use App\Services\CacheInvalidationServiceInterface;` from imports if no other method in the class uses it. **Check first** — the constructor injects it at line 17, so the import stays.

### Phase 2 — Remove `collectFirstNLevels()` from org-chart

Delete lines 267–277 from `resources/views/livewire/hr/org-chart.blade.php`:

```php
protected function collectFirstNLevels($nodes, int $maxLevel, int $level = 1): array
{
    $ids = [];
    foreach ($nodes as $node) {
        if ($level <= $maxLevel) {
            $ids[] = (string) $node->id;
        }
    }
    return $ids;
}
```

### Phase 3 — Remove HardwareUpdated event chain

1. **Delete** `app/Events/HardwareUpdated.php`
2. **Delete** `app/Listeners/HardwareGisCacheListener.php`
3. **Update** `app/Providers/EventServiceProvider.php`:
   - Remove the `use App\Events\HardwareUpdated;` import (line 5)
   - Remove the `use App\Listeners\HardwareGisCacheListener;` import (line 6)
   - Empty the `$listen` array:
   ```php
   protected $listen = [];
   ```
   Or remove the `$listen` property entirely if `EventServiceProvider` has no other event mappings.

4. **Remove** `event(new HardwareUpdated(...))` calls from `app/Http/Controllers/Api/HardwareController.php`:
   - Line 185: `event(new HardwareUpdated($hardware, 'created'));`
   - Line 221: `event(new HardwareUpdated($hardware, 'updated'));`
   - Line 234: `event(new HardwareUpdated($hardware, 'deleted'));`
   - Line 310: `event(new HardwareUpdated($hardwares->first(), 'bulk_mark'));`

5. Remove the `use App\Events\HardwareUpdated;` import from HardwareController.

### Phase 4 — Verify cache invalidation still works

Confirm `Hardware::flushStatsCache()` (called on `saved`/`deleted` boot events) already handles all the cache namespaces:

```php
// Hardware.php:81-88
public static function flushStatsCache(): void
{
    $cache = app(CacheInvalidationServiceInterface::class);
    $cache->increment('hardware_stats');
    $cache->increment('gis');      // <-- covers the removed listener
    $cache->increment('maps');
    $cache->increment('dashboard');
}
```

This is called on `saved` (line 68) and `deleted` (line 69) boot events, so it already covers create/update/delete. The `bulk_mark` action at HardwareController:310 is handled because `bulk_mark` uses `update()` which fires `saved`.

### Phase 5 — Run tests

```bash
composer test
vendor/bin/pint --dirty --format agent
```

### Phase 6 — Check phpstan-baseline

If any removed files appear in `phpstan-baseline.neon`, remove their entries:

```bash
grep -n 'HardwareUpdated\|HardwareGisCacheListener\|invalidateCache\|collectFirstNLevels' phpstan-baseline.neon
```

## Test plan

- `composer test` — full suite should pass with no regressions.
- GIS cache invalidation still works via `Hardware::flushStatsCache()`.
- Manual: create/update/delete hardware and verify GIS map data updates correctly.

## Done criteria

- [ ] `app/Events/HardwareUpdated.php` deleted
- [ ] `app/Listeners/HardwareGisCacheListener.php` deleted
- [ ] `EventServiceProvider::$listen` is empty (or removed)
- [ ] `GisController::invalidateCache()` removed
- [ ] `collectFirstNLevels()` removed from org-chart
- [ ] All `event(new HardwareUpdated(...))` calls removed from HardwareController
- [ ] All tests pass
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If removing the event breaks any test that asserts on `HardwareUpdated` being dispatched, STOP and check the test.
- If `phpstan` raises errors about removed classes, STOP and update the baseline.
- If the `EventServiceProvider` has other event mappings besides this one, only remove the HardwareUpdated entry.
