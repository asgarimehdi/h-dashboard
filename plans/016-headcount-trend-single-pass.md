# perf: the headcount trend runs a correlated subquery per month

> **Plan 016** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P3` · formerly GitHub issue #1152 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Http/Controllers/Api/HrAnalyticsController.php database/migrations`

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

`headcountTrend` computes a **correlated subquery per month bucket**: for each of 12–24 generated months it runs a `COUNT(*)` over the in-scope persons with `created_at < month + 1 month`. That is 12–24 passes over the same rows per cache miss (5-minute TTL), and no index covers the `(u_id, created_at)` predicate, so each pass filters. One `GROUP BY date_trunc('month', created_at)` plus a cumulative window collapses it to a single pass with identical output.

## Current state

- `app/Http/Controllers/Api/HrAnalyticsController.php:44-62`:

```php
$results = DB::select(
    "WITH month_series AS (
        SELECT generate_series(
            date_trunc('month', CURRENT_DATE) - make_interval(months => ?),
            date_trunc('month', CURRENT_DATE),
            '1 month'::interval
        ) AS month_start
    )
    SELECT
        to_char(ms.month_start, 'YYYY-MM') AS month,
        (SELECT COUNT(*)
         FROM persons p
         WHERE p.u_id = ANY(?)
           AND p.created_at < ms.month_start + INTERVAL '1 month') AS count
    FROM month_series ms
    ORDER BY ms.month_start ASC",
    [(string) ($months - 1), $idArray]
);
```

- `:220` (or nearby) builds `$idArray = '{'.implode(',', …).'}'` from `$accessibleIds`.
- `database/migrations/2026_07_30_035023_add_composite_indexes_to_persons_table.php:23-32` — has `(u_id, n_code)`, `(u_id, f_name, l_name)`, `(u_id, s_id)`; **nothing covers `(u_id, created_at)`**.
- Contract to preserve: `months=12` yields exactly 12 buckets, oldest-first, empty months read as zero (the same shape `DailySeries`/the monthly trend endpoints use). The stock basis is `created_at` (`hire_date` is unpopulated) — do not switch columns.
- Repo convention: monthly cumulative stock over a `generate_series` window mirrors `DailySeries` (#736/#952) — see `references/api-endpoints.md` "Performance" for the documented pattern.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/HrAnalyticsTest.php` (find: `grep -rl "headcount" tests/`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Http/Controllers/Api/HrAnalyticsController.php` (the `headcountTrend` query only)
- `database/migrations/<new>_add_u_id_created_at_index_to_persons.php` (optional — see step 3)
- The HR analytics test file

**Out of scope** (do NOT touch):
- `vacancyTrend`, `staffingRatio`, `loadSubtree` — separate queries; only if they share the exact same correlated shape and you can change them with the same test coverage, otherwise leave them.
- The `?months=` validation contract (`ReportDays`; 0/negative/above-24 are 422).
- The cache key/TTL.

## Git workflow

- Branch: `perf/headcount-trend-single-pass`
- Commit style: `perf: compute the headcount trend in one grouped pass (#016)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Rewrite as group-by + cumulative window

Replace the statement with a single aggregate left-joined to the series, then a window sum:

```sql
WITH months AS (
    SELECT generate_series(
        date_trunc('month', CURRENT_DATE) - make_interval(months => ?),
        date_trunc('month', CURRENT_DATE),
        '1 month'::interval
    ) AS month_start
),
monthly AS (
    SELECT date_trunc('month', p.created_at) AS m, COUNT(*) AS c
    FROM persons p
    WHERE p.u_id = ANY(?)
    GROUP BY 1
)
SELECT
    to_char(ms.month_start, 'YYYY-MM') AS month,
    COALESCE(SUM(m.c) FILTER (WHERE m.m IS NOT NULL) OVER (ORDER BY ms.month_start), 0)::int AS count
FROM months ms
LEFT JOIN monthly m ON m.m = ms.month_start
ORDER BY ms.month_start ASC
```

Because the stock is "hired before the first of the next month", a person counted in a month stays counted every month after — a cumulative sum over months that have hires reproduces the original correlated subquery exactly. Verify the boundary: a person with `created_at` in month M must appear in M's bucket and every later one (the original used `< month_start + 1 month`, i.e. inclusive of M). The `GROUP BY date_trunc('month', created_at)` naturally puts them in M.

Coerce `count` to `int` in the PHP mapping (it already does `(int) $row->count`).

**Verify**: `XDEBUG_MODE=off php artisan test <hr analytics test>` → all pass, especially any test asserting exact bucket counts.

### Step 2: Prove equivalence against the old expression

Temporarily run both statements against the dev DB (or in a test) for the same scope and `months`, and assert they return identical `month`/`count` pairs. If they differ, the boundary is off — fix the comparison (likely an off-by-one on the cumulative direction) rather than the test.

**Verify**: both statements return identical rows (paste the comparison in the PR).

### Step 3: (Optional) add the covering index

Only if `EXPLAIN (ANALYZE)` shows the `monthly` CTE scanning more than the scope's rows, add:

```php
$table->index(['u_id', 'created_at']);
```

to `persons`. This is a qualification aid, not a correctness change; include it only with the EXPLAIN evidence.

**Verify**: `php artisan migrate` → applies; EXPLAIN shows an index scan.

## Test plan

- Keep/extend the HR analytics tests asserting the trend's bucket count, oldest-first order, and zero-filled empty months.
- Add a test with persons hired in three known months, asserting the exact cumulative series (e.g. 2, 2, 5, 5 …) — this is the regression guard for the boundary.
- Structural pattern: existing `headcountTrend` tests; seed persons with `forceFill(['created_at' => …])->saveQuietly()` because `created_at` is not fillable.
- Verification: `XDEBUG_MODE=off php artisan test <file>` → all pass.

## Done criteria

- [ ] The correlated per-bucket subquery is gone; one grouped pass + window sum remains
- [ ] Old vs new statements return identical series (evidence in PR)
- [ ] `months=12` returns 12 buckets, oldest-first, empty months `0`
- [ ] `XDEBUG_MODE=off php artisan test <hr analytics test>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- The new statement disagrees with the old one on any bucket — report the differing buckets rather than adjusting the test.
- `created_at` is nullable in a way the original handled differently (the original's `< month+1` also implicitly excludes nulls) — confirm and report if so.
- A test asserts the **SQL text** (unlikely) rather than the result.

## Maintenance notes

- If `hire_date` is ever populated and the stock basis changes, this query must change with it — the basis is documented as `created_at` deliberately.
- Reviewer should scrutinize: the cumulative boundary (a person hired in the current month must be counted in the current month's bucket).
- Deferred: the same treatment for `vacancyTrend`/`staffingRatio` if they prove hot — separate issue.
