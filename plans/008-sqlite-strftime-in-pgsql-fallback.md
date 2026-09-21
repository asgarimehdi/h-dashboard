# Plan 8: Remove dead SQLite fallback branches in HR controllers

> Written against commit: `9ab05f5` (beta/sydney)
> Category: Bug | Effort: S | Impact: HIGH

## Problem

`HrAnalyticsController` and `HrStatsController` contain SQLite fallback branches guarded by `DB::getDriverName() === 'pgsql'`. These fallbacks use SQLite-specific functions like `strftime('%Y-%m', created_at)` that would fail on PostgreSQL — but they can never execute in any environment:

- **Production:** PostgreSQL → always takes the `pgsql` branch
- **Tests:** `phpunit.xml` sets `DB_CONNECTION=pgsql` → always takes the `pgsql` branch
- **CI:** `.github/workflows/test.yml` uses `postgis/postgis:16-3.4` → always takes the `pgsql` branch

The fallback branches are dead code. Worse, if a future developer removes the `if (pgsql)` guard thinking the fallback is needed, the `strftime()` call would cause a 500 error on PostgreSQL.

### Evidence

| File | Lines | Dead fallback content |
|------|-------|-----------------------|
| `app/Http/Controllers/Api/HrAnalyticsController.php` | 54–64 | `selectRaw("strftime('%Y-%m', created_at) ...")` |
| `app/Http/Controllers/Api/HrAnalyticsController.php` | 122–146 | `$allPersonnel = Person::whereIn(...)` with manual grouping |
| `app/Http/Controllers/Api/HrAnalyticsController.php` | 197–219 | `$persons = Person::whereIn(...)` with `join` + `pluck` |
| `app/Http/Controllers/Api/HrStatsController.php` | 70–97 | `$persons = Person::whereIn(...)` with manual `$group` closure |

**No tests exercise these fallback branches.** `HrApiTest.php` tests hit all 3 analytics endpoints but always run on PostgreSQL, so the `pgsql` branch is always taken.

## Solution

Remove the `DB::getDriverName() === 'pgsql'` guards and the dead fallback branches. The PostgreSQL-only code becomes the sole code path. Since it's already well-tested by `HrApiTest.php`, this is safe.

### Before (HrAnalyticsController.php:35–65)

```php
if (DB::getDriverName() === 'pgsql') {
    $idList = implode(',', array_map('intval', $accessibleIds));
    $results = DB::select(
        "SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month, ..."
    );
    return collect($results)->map(...)->values();
}

// Fallback for non-PgSQL drivers (e.g. SQLite in tests)
return Person::whereIn('u_id', $accessibleIds)
    ->where('created_at', '>=', $since)
    ->selectRaw("strftime('%Y-%m', created_at) AS month, COUNT(*) AS count")
    ->groupBy('month')
    ->orderBy('month')
    ->get()
    ->map(...)->values();
```

### After

```php
$idList = implode(',', array_map('intval', $accessibleIds));
$results = DB::select(
    "SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month, ..."
);
return collect($results)->map(...)->values();
```

The `if` guard, `else`/fallback branch, and the `// Fallback` comment are all deleted.

## Files in Scope

- `app/Http/Controllers/Api/HrAnalyticsController.php` — 3 dead fallback branches (lines 54–64, 122–146, 197–219)
- `app/Http/Controllers/Api/HrStatsController.php` — 1 dead fallback branch (lines 70–97)

## Files Out of Scope

- `app/Http/Controllers/Api/HardwareAuditController.php:208` — its `getDriverName() === 'pgsql'` guard is for a PostgreSQL-specific sequence reset (`pg_get_serial_sequence`) with no fallback needed — not dead code
- Other controllers — no `strftime()` or SQLite fallback patterns found
- Tests — `HrApiTest.php` already covers all analytics endpoints

## Steps

### Step 1: HrAnalyticsController — headcountTrend (lines 35–65)

1. Remove the `if (DB::getDriverName() === 'pgsql')` guard at line 35
2. Remove the `}` closing the if-block and the fallback branch (lines 54–64)
3. Remove the `// Fallback for non-PgSQL drivers (e.g. SQLite in tests)` comment
4. Dedent the remaining PostgreSQL code by one level
5. Verify: `grep -n "strftime" app/Http/Controllers/Api/HrAnalyticsController.php` returns 0 results

### Step 2: HrAnalyticsController — vacancyTrend (lines 83–146)

1. Remove the `if (DB::getDriverName() === 'pgsql')` guard at line 83
2. Remove the `}` closing the if-block and the fallback branch (lines 122–146)
3. Remove the `// Fallback for non-PgSQL (e.g., SQLite)` comment
4. Dedent the remaining PostgreSQL code by one level
5. Verify: `grep -n "Fallback for non-PgSQL" app/Http/Controllers/Api/HrAnalyticsController.php` returns 0 results

### Step 3: HrAnalyticsController — staffingRatio (lines 165–219)

1. Remove the `if (DB::getDriverName() === 'pgsql')` guard at line 165
2. Remove the `}` closing the if-block and the fallback branch (lines 197–219)
3. Remove the `// Fallback for non-PgSQL drivers (e.g. SQLite in tests)` comment
4. Dedent the remaining PostgreSQL code by one level
5. Remove the unused `Person` and `Unit` model imports if no longer referenced

### Step 4: HrStatsController — stats (lines 32–97)

1. Remove the `if (DB::getDriverName() === 'pgsql')` guard at line 32
2. Remove the `}` closing the if-block and the fallback branch (lines 70–97)
3. Remove the `// Fallback for non-PgSQL drivers (e.g. SQLite in tests)` comment
4. Dedent the remaining PostgreSQL code by one level
5. Remove unused `Person` import if no longer referenced

### Step 5: Verify

```bash
grep -rn "strftime\|Fallback for non-PgSQL\|getDriverName.*pgsql" app/Http/Controllers/
# Expected: only HardwareAuditController.php:208 remains (legitimate sequence reset)
composer phpstan    # No new errors
composer pint       # Format
composer test       # All tests pass
```

## Test Plan

1. All existing tests in `tests/Feature/HrApiTest.php` exercise the affected endpoints — they should pass unchanged:
   - `test_headcount_trend_returns_monthly_data`
   - `test_vacancy_trend_returns_monthly_data`
   - `test_staffing_ratio_returns_aggregations`
   - `test_stats_returns_aggregations`
2. No new tests needed — we're removing dead code, not changing behavior
3. `composer test` — all 1352+ tests pass
4. `composer phpstan` — zero new errors
5. `composer pint` — code is formatted

## Maintenance Note

If the project ever adds SQLite support (e.g., for local development without Docker), these fallback branches would need to be re-added using SQLite-compatible date functions (`strftime()` is correct for SQLite, but `to_char()` is not). Document this decision in `AGENTS.md` Gotchas table. For now, SQLite is not a supported driver.

## Done Criteria

- [ ] `grep -rn "strftime" app/Http/Controllers/` returns 0 results
- [ ] `grep -rn "Fallback for non-PgSQL" app/Http/Controllers/` returns 0 results
- [ ] `grep -c "getDriverName.*pgsql" app/Http/Controllers/Api/HrAnalyticsController.php` returns 0
- [ ] `grep -c "getDriverName.*pgsql" app/Http/Controllers/Api/HrStatsController.php` returns 0
- [ ] `composer test` — all tests pass
- [ ] `composer phpstan` — clean
- [ ] `composer pint` — formatted
