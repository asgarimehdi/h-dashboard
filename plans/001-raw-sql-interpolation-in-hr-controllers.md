# Plan 001: Raw SQL Interpolation in HrStatsController + HrAnalyticsController

> Written against commit: `7e0052e` (beta/sydney)
> Category: Security | Effort: M | Impact: HIGH

## Problem

`HrStatsController` and `HrAnalyticsController` build raw SQL queries by string-interpolating `$idList` — an `implode(',', array_map('intval', $accessibleIds))` result. While `intval()` currently prevents SQL injection, the pattern is fragile: one refactoring that removes the cast, or passes non-integer data, turns this into a critical vulnerability.

**Compare** with `Unit.php:165-176` which uses safe parameterized binding:
```php
$placeholders = implode(',', array_fill(0, count($ids), '?'));
\DB::select("SELECT ... WHERE id IN ({$placeholders})", $ids);
```

### Evidence

- `app/Http/Controllers/Api/HrStatsController.php:33-57` — 6 subqueries interpolate `{$idList}` directly
- `app/Http/Controllers/Api/HrAnalyticsController.php:36-44` — `headcountTrend` interpolates `{$idList}`
- `app/Http/Controllers/Api/HrAnalyticsController.php:84-113` — `vacancyTrend` interpolates `{$idList}` AND `{$months}` (line 91)
- `app/Http/Controllers/Api/HrAnalyticsController.php:163-178` — `staffingRatio` interpolates `{$idList}`

## Solution

Replace all string-interpolated ID lists with parameterized PostgreSQL `ANY(?)` syntax, using an array parameter.

### Before (HrStatsController.php:36-57)

```php
$idList = implode(',', array_map('intval', $accessibleIds));
$row = DB::selectOne(
    "SELECT
        (SELECT count(*) FROM persons WHERE u_id IN ({$idList})) AS total,
        ..."
);
```

### After

```php
$row = DB::selectOne(
    "SELECT
        (SELECT count(*) FROM persons WHERE u_id = ANY(?)) AS total,
        (SELECT coalesce(jsonb_object_agg(...), '{}')
           FROM (SELECT p.u_id, u.name, count(*) AS c
                 FROM persons p LEFT JOIN units u ON p.u_id = u.id
                 WHERE p.u_id = ANY(?) GROUP BY p.u_id, u.name) x) AS by_unit,
        ..."
    ,
    [$accessibleIds, $accessibleIds, /* ... one binding per subquery ... */]
);
```

PostgreSQL's `= ANY(?)` accepts a PHP array directly when using the pgsql driver. This eliminates string interpolation entirely.

### For `$months` in vacancyTrend (line 91)

Replace:
```php
"interval '{$months} months'"
```
With:
```php
"interval '?' month"
```
And bind `(string) $months` as a parameter. Note: PostgreSQL interval accepts a single integer, not `N months` — use `interval ? || ' month'` or cast to string.

## Files in Scope

- `app/Http/Controllers/Api/HrStatsController.php`
- `app/Http/Controllers/Api/HrAnalyticsController.php`

## Files Out of Scope

- `app/Models/Unit.php` (already uses safe pattern)
- Other controllers (no raw SQL interpolation found)

## Steps

### Step 1: HrStatsController — parameterize all subqueries

1. Remove `$idList` variable
2. Replace all `u_id IN ({$idList})` with `u_id = ANY(?)`
3. Pass `[$accessibleIds]` as binding for each `?` — since the same array is used in every subquery, each needs its own `?`
4. Verify the SQL structure is still valid with `php artisan tinker --execute 'DB::select("SELECT ? = ANY(?)", ["test", ["a","b"]])'`

### Step 2: HrAnalyticsController::headcountTrend — parameterize

1. Remove `$idList` at line 36
2. Replace `u_id IN ({$idList})` with `u_id = ANY(?)`
3. Bind: `[$accessibleIds, $since]`

### Step 3: HrAnalyticsController::vacancyTrend — parameterize ID list + months

1. Replace `'{'.implode(...).'}'` array literal at line 84 with `[$accessibleIds]`
2. Replace `interval '{$months} months'` with a parameterized approach
3. Bind: `[$accessibleIds]`
4. The months interval needs special handling — use `"generate_series(CURRENT_DATE - interval ? || ' month', ...)"` with `$months` as string binding

### Step 4: HrAnalyticsController::staffingRatio — parameterize

1. Remove `$idList` at line 163
2. Replace `u_id IN ({$idList})` with `u_id = ANY(?)`
3. Bind: `[$accessibleIds]`

### Step 5: Verify

```bash
composer phpstan    # Ensure no new errors
composer pint       # Format
composer test       # All 1352 tests pass
```

## Test Plan

1. Existing tests in `tests/Feature/HrApiTest.php` and `tests/Feature/HrLivewireTest.php` should pass unchanged (they exercise the same endpoints)
2. Add a test that passes a very large number of accessible IDs (e.g., 500) to verify the parameterized query doesn't hit query size limits
3. Verify no raw SQL interpolation remains: `grep -rn "implode.*intval" app/Http/Controllers/`

## Maintenance Note

If the project ever adds MySQL support (there's a `.env.example.mysql`), the `= ANY(?)` syntax won't work. Use `WHERE u_id IN (SELECT UNNEST(?))` or keep the PostgreSQL-specific branch with a driver check.

## Done Criteria

- [ ] Zero `implode(',', array_map('intval',` patterns in HrStatsController and HrAnalyticsController
- [ ] All existing tests pass (`composer test`)
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] `grep -rn "implode.*intval" app/Http/Controllers/` returns 0 results
