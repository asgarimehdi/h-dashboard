# perf: per-scope cache keys depend on the id list's order and type

> **Plan 017** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P3` · formerly GitHub issue #1153 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Http/Controllers/Api/HrStatsController.php app/Http/Controllers/Api/HrAnalyticsController.php app/Http/Controllers/Api/HardwareController.php app/Http/Controllers/Api/ReportController.php`

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

Five controllers build their per-scope cache key by hashing the accessible-unit **ID list in whatever order the query returned**. PostgreSQL does not guarantee an order for `IN`/CTE results, and `AccessService` caches that order verbatim (`Unit::descendantIds()` has no `ORDER BY`). Two users with the same effective scope can therefore compute different keys, each paying for the same expensive HR/report/hardware-stats query and each holding a separate cache entry. The GIS controller already sorts before hashing — that is the intended pattern, and it should be the shared one.

## Current state

- `app/Http/Controllers/Api/HrStatsController.php:82` and `:199`:

```php
$scopeHash = md5(implode(',', $accessibleIds));
```

- `app/Http/Controllers/Api/HrAnalyticsController.php:203` — same `implode` form.
- `app/Http/Controllers/Api/HardwareController.php:262` — `md5(json_encode($accessibleIds))`.
- `app/Http/Controllers/Api/ReportController.php:28,63,104` — `md5(json_encode($accessibleIds))`.
- The correct existing pattern, GIS, sorts and normalizes first — `app/Http/Controllers/Api/GisController.php:98-107`:

```php
$idStrings = array_map('strval', $accessibleIds);
sort($idStrings, SORT_STRING);
$scopeHash = md5(implode(',', $idStrings));
```

- `app/Models/Unit.php:278-289` (`recursiveDescendantQuery`) — the recursive CTE `SELECT id FROM unit_tree` with **no** `ORDER BY`.
- `app/Services/AccessService.php:89-92` — caches the ID list as resolved.
- **Settled constraint**: the `descendantIds` CTE uses `UNION` (not `UNION ALL`) deliberately so a `parent_id` cycle terminates — do not change the CTE's set operator.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/HrStatsTest.php tests/Feature/ReportTest.php tests/Feature/HardwareIndexTest.php` (adjust to the real names) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Http/Controllers/Api/HrStatsController.php`
- `app/Http/Controllers/Api/HrAnalyticsController.php`
- `app/Http/Controllers/Api/HardwareController.php`
- `app/Http/Controllers/Api/ReportController.php`
- Optionally a new small helper (e.g. `app/Support/ScopeCacheKey.php`) used by all five
- The affected tests

**Out of scope** (do NOT touch):
- `app/Models/Unit.php`'s CTE (adding an `ORDER BY` there is an alternative, but it changes a query the whole app shares and is deliberately minimal — prefer normalizing at the key builder).
- `app/Services/AccessService.php`'s caching semantics.
- `GisController` — already correct; leave it (or switch it to the shared helper for consistency, but only if the helper matches its current behaviour exactly).
- The cache version keys, namespaces, and TTLs.

## Git workflow

- Branch: `perf/scope-cache-key-normalization`
- Commit style: `perf: derive scope cache keys from a sorted id list (#017)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Add one shared key builder

Create `app/Support/ScopeCacheKey.php`:

```php
final class ScopeCacheKey
{
    /** @param array<int|string> $accessibleIds */
    public static function hash(array $accessibleIds): string
    {
        $ids = array_map('intval', $accessibleIds);
        sort($ids, SORT_NUMERIC);

        return md5(implode(',', $ids));
    }
}
```

Normalizing to `int` also removes the `"1"` vs `1` divergence between the `json_encode` and `implode` forms.

**Verify**: `vendor/bin/pint --dirty --format agent` → no diff; `grep -c "class ScopeCacheKey" app/Support/ScopeCacheKey.php` → 1.

### Step 2: Use it in all five builders

Replace each `md5(implode(',', $accessibleIds))` / `md5(json_encode($accessibleIds))` with `ScopeCacheKey::hash($accessibleIds)`. This changes existing key **values**, which only causes a cold miss once per key — acceptable and self-healing (old entries expire by TTL).

**Verify**: `grep -rn "md5(implode(',', \$accessibleIds))\|md5(json_encode(\$accessibleIds))" app/Http/Controllers/` → no matches.

### Step 3: Pin the invariance

Add a test that the same scope in two different orders produces the same key — assert through the controller (two requests with the scope resolved differently) or unit-test `ScopeCacheKey::hash([3,1,2]) === ScopeCacheKey::hash([1,2,3])`.

**Verify**: `XDEBUG_MODE=off php artisan test <new test or affected files>` → all pass.

## Test plan

- New unit test for `ScopeCacheKey::hash`: same set in different orders → same hash; `[1,2]` vs `["1","2"]` → same hash.
- New/updated Feature test proving a controller's key is order-independent (e.g. hit the endpoint twice with a scope whose resolution order is seeded differently, and assert one cache entry / identical response).
- Structural pattern: existing cache-invalidation tests; the shared trait's `assertCacheInvalidated()`.
- Verification: `XDEBUG_MODE=off php artisan test <files>` → all pass.

## Done criteria

- [ ] `grep -rn "md5(json_encode(\$accessibleIds))\|md5(implode(',', \$accessibleIds))" app/Http/Controllers/` returns no matches
- [ ] `ScopeCacheKey::hash` exists and is used by all five (four controllers + optionally GIS)
- [ ] Order-invariance test passes
- [ ] `XDEBUG_MODE=off php artisan test <files>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- A controller's key already included something order-dependent beyond the id list in a way the helper would drop — report the extra component.
- Changing key values breaks a test that asserts a literal key string — report; asserting literal keys is brittle and the test may need to change, but confirm first.
- `ScopeCacheKey` would collide with an existing class name (`grep -rn "ScopeCacheKey" app/`).

## Maintenance notes

- New controllers that cache per scope must use `ScopeCacheKey::hash()`; add that to the caching section of `references/api-endpoints.md` if it names the convention.
- Reviewer should scrutinize: that the hash input is normalized to ints (removing the `"1"` vs `1` divergence), and that no TTL/version changed.
