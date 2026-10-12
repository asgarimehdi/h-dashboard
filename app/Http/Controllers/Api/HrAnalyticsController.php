<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Rules\ReportMonths;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HR time-series analytics — extracted from HrController.
 */
class HrAnalyticsController extends Controller
{
    use PersianNormalizer;

    /**
     * GET /api/hr/analytics/headcount-trend — cumulative headcount per month.
     *
     * A stock, not a flow (issue #952): bucket M counts every in-scope person
     * hired on or before the end of M, so a hire in January is still counted
     * in June. The window is materialised with `generate_series` following
     * the `DailySeries` pattern (#736), so a month with no hires is a zero,
     * never a missing bucket. Buckets run oldest first; `months=12` yields
     * exactly 12 buckets.
     *
     * Stock basis is `created_at`: `hire_date` exists for exactly this but is
     * unpopulated for every row, so it cannot anchor the count. Revisit when
     * `hire_date` is populated.
     */
    public function headcountTrend(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $months = $this->months($request);

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('headcount_trend', $accessibleIds, $months),
            now()->addMinutes(5),
            function () use ($accessibleIds, $months) {
                $idArray = '{'.implode(',', array_map('intval', $accessibleIds)).'}';
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

                return collect($results)->map(fn ($row) => [
                    'month' => $row->month,
                    'count' => (int) $row->count,
                ])->values();
            }
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/hr/analytics/vacancy-trend — monthly vacancy count (units with zero personnel).
     *
     * A stock, not a flow (issue #952): a unit staffed in month M counts as
     * staffed for M and every month after, so the `staffed` CTE records each
     * unit's first staffed month and joins on `u_id` alone — never on month
     * equality, which is what reported the whole organisation as vacant in
     * every month without a hire. Same materialised window and ordering as
     * `headcountTrend` above.
     *
     * Stock basis is `created_at`, for the same unpopulated-`hire_date`
     * reason documented on `headcountTrend`.
     */
    public function vacancyTrend(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $months = $this->months($request);

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('vacancy_trend', $accessibleIds, $months),
            now()->addMinutes(5),
            function () use ($accessibleIds, $months) {
                $idArray = '{'.implode(',', array_map('intval', $accessibleIds)).'}';
                $results = DB::select(
                    "WITH accessible_units AS (
                        SELECT id FROM units WHERE id = ANY(?)
                    ),
                    month_series AS (
                        SELECT generate_series(
                            date_trunc('month', CURRENT_DATE) - make_interval(months => ?),
                            date_trunc('month', CURRENT_DATE),
                            '1 month'::interval
                        ) AS month_start
                    ),
                    staffed AS (
                        SELECT
                            p.u_id,
                            min(date_trunc('month', p.created_at)) AS first_month
                        FROM persons p
                        WHERE p.u_id = ANY(ARRAY(SELECT id FROM accessible_units))
                        GROUP BY p.u_id
                    )
                    SELECT
                        to_char(ms.month_start, 'YYYY-MM') AS month,
                        COUNT(au.id) - COUNT(s.u_id) AS vacant_count
                    FROM month_series ms
                    CROSS JOIN accessible_units au
                    LEFT JOIN staffed s ON s.u_id = au.id AND s.first_month <= ms.month_start
                    GROUP BY ms.month_start
                    ORDER BY ms.month_start ASC",
                    [$idArray, (string) ($months - 1)]
                );

                return collect($results)->map(fn ($row) => [
                    'month' => $row->month,
                    'count' => (int) $row->vacant_count,
                ]);
            }
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/hr/analytics/staffing-ratio — personnel count per unit_type and per semat.
     */
    public function staffingRatio(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('staffing_ratio', $accessibleIds),
            now()->addMinutes(5),
            function () use ($accessibleIds) {
                $idArray = '{'.implode(',', array_map('intval', $accessibleIds)).'}';

                $rows = DB::select(
                    "SELECT 'unit_type' AS kind, COALESCE(ut.name, 'نامشخص') AS label, COUNT(*) AS total
                     FROM persons p
                     JOIN units u ON p.u_id = u.id
                     LEFT JOIN unit_types ut ON u.unit_type_id = ut.id
                     WHERE p.u_id = ANY(?)
                     GROUP BY ut.name
                     UNION ALL
                     SELECT 'semat' AS kind, COALESCE(s.name, 'نامشخص') AS label, COUNT(*) AS total
                     FROM persons p
                     JOIN semats s ON p.s_id = s.id
                     WHERE p.u_id = ANY(?) AND p.s_id IS NOT NULL
                     GROUP BY s.name",
                    [$idArray, $idArray]
                );

                $byUnitType = [];
                $bySemat = [];
                foreach ($rows as $row) {
                    if ($row->kind === 'unit_type') {
                        $byUnitType[$row->label] = (int) $row->total;
                    } else {
                        $bySemat[$row->label] = (int) $row->total;
                    }
                }

                return [
                    'by_unit_type' => $byUnitType,
                    'by_semat' => $bySemat,
                ];
            }
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Resolve and validate the `?months=` window (default 12, cap 24).
     *
     * Mirrors `ReportController::days()`: the value is validated against the
     * `ReportDays`-style contract first, so 0 and negatives are 422s rather
     * than silent window collapses. The `min()` stays as defence in depth in
     * case validation is ever bypassed, the way `PerPage` keeps it (#894).
     */
    private function months(UnitScopedRequest $request): int
    {
        $request->validate(['months' => ['sometimes', new ReportMonths]]);

        return min(ReportMonths::resolveMonths($request->query('months')), ReportMonths::MAX_MONTHS);
    }

    private function hrAnalyticsCacheKey(string $type, array $accessibleIds, int $months = 12): string
    {
        $version = Cache::get('hr_stats_version', 0);
        $scopeHash = md5(implode(',', $accessibleIds));

        return "hr:analytics:{$type}:v{$version}:m{$months}:{$scopeHash}";
    }
}
