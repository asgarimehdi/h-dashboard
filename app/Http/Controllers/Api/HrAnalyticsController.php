<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Person;
use App\Models\Unit;
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
     * GET /api/hr/analytics/headcount-trend — monthly headcount for last N months.
     */
    public function headcountTrend(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $months = min((int) $request->get('months', 12), 24);

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('headcount_trend', $accessibleIds, $months),
            now()->addMinutes(5),
            function () use ($accessibleIds, $months) {
                $since = now()->subMonths($months)->startOfMonth();

                if (DB::getDriverName() === 'pgsql') {
                    $idList = implode(',', array_map('intval', $accessibleIds));
                    $results = DB::select(
                        "SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month,
                                COUNT(*) AS count
                         FROM persons
                         WHERE u_id IN ({$idList})
                           AND created_at >= ?
                         GROUP BY date_trunc('month', created_at)
                         ORDER BY date_trunc('month', created_at) ASC",
                        [$since]
                    );

                    return collect($results)->map(fn ($row) => [
                        'month' => $row->month,
                        'count' => (int) $row->count,
                    ])->values();
                }

                // Fallback for non-PgSQL drivers (e.g. SQLite in tests)
                return Person::whereIn('u_id', $accessibleIds)
                    ->where('created_at', '>=', $since)
                    ->selectRaw("strftime('%Y-%m', created_at) AS month, COUNT(*) AS count")
                    ->groupBy('month')
                    ->orderBy('month')
                    ->get()
                    ->map(fn ($row) => [
                        'month' => $row->month,
                        'count' => (int) $row->count,
                    ])->values();
            }
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/hr/analytics/vacancy-trend — monthly vacancy count (units with zero personnel).
     */
    public function vacancyTrend(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $months = min((int) $request->get('months', 12), 24);

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('vacancy_trend', $accessibleIds, $months),
            now()->addMinutes(5),
            function () use ($accessibleIds, $months) {
                if (DB::getDriverName() === 'pgsql') {
                    $idList = '{'.implode(',', array_map('intval', $accessibleIds)).'}';
                    $results = DB::select(
                        "WITH accessible_units AS (
                            SELECT id FROM units WHERE id = ANY(?)
                        ),
                        month_series AS (
                            SELECT generate_series(
                                date_trunc('month', CURRENT_DATE) - interval '{$months} months',
                                date_trunc('month', CURRENT_DATE),
                                '1 month'::interval
                            ) AS month_start
                        ),
                        personnel_counts AS (
                            SELECT
                                date_trunc('month', p.created_at) AS month_start,
                                p.u_id,
                                COUNT(*) AS personnel_count
                            FROM persons p
                            WHERE p.u_id = ANY(ARRAY(SELECT id FROM accessible_units))
                            GROUP BY date_trunc('month', p.created_at), p.u_id
                        )
                        SELECT
                            to_char(ms.month_start, 'YYYY-MM') AS month,
                            COUNT(au.id) - COUNT(pc.u_id) AS vacant_count
                        FROM month_series ms
                        CROSS JOIN accessible_units au
                        LEFT JOIN personnel_counts pc ON pc.month_start = ms.month_start AND pc.u_id = au.id
                        GROUP BY ms.month_start
                        ORDER BY ms.month_start DESC",
                        [$idList]
                    );

                    return collect($results)->map(fn ($row) => [
                        'month' => $row->month,
                        'count' => (int) $row->vacant_count,
                    ]);
                }

                // Fallback for non-PgSQL (e.g., SQLite)
                $since = now()->subMonths($months)->startOfMonth();
                $allPersonnel = Person::whereIn('u_id', $accessibleIds)
                    ->where('created_at', '>=', $since)
                    ->select('u_id', 'created_at')
                    ->get()
                    ->groupBy(fn ($p) => $p->created_at->format('Y-m'));

                $results = [];
                for ($i = $months; $i >= 0; $i--) {
                    $monthDate = now()->subMonths($i);
                    $monthLabel = $monthDate->format('Y-m');

                    $personnelInMonth = $allPersonnel->get($monthLabel, collect());
                    $unitsWithPersonnel = $personnelInMonth->pluck('u_id')->unique();

                    $vacantCount = Unit::whereIn('id', $accessibleIds)
                        ->whereNotIn('id', $unitsWithPersonnel)
                        ->count();

                    $results[] = ['month' => $monthLabel, 'count' => $vacantCount];
                }

                return collect($results);
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
                $idList = implode(',', array_map('intval', $accessibleIds));

                if (DB::getDriverName() === 'pgsql') {
                    $rows = DB::select(
                        "SELECT 'unit_type' AS kind, COALESCE(ut.name, 'نامشخص') AS label, COUNT(*) AS total
                         FROM persons p
                         JOIN units u ON p.u_id = u.id
                         LEFT JOIN unit_types ut ON u.unit_type_id = ut.id
                         WHERE p.u_id IN ({$idList})
                         GROUP BY ut.name
                         UNION ALL
                         SELECT 'semat' AS kind, COALESCE(s.name, 'نامشخص') AS label, COUNT(*) AS total
                         FROM persons p
                         JOIN semats s ON p.s_id = s.id
                         WHERE p.u_id IN ({$idList}) AND p.s_id IS NOT NULL
                         GROUP BY s.name"
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

                // Fallback for non-PgSQL drivers (e.g. SQLite in tests)
                $persons = Person::whereIn('u_id', $accessibleIds);

                $byUnitType = (clone $persons)
                    ->join('units', 'persons.u_id', '=', 'units.id')
                    ->leftJoin('unit_types', 'units.unit_type_id', '=', 'unit_types.id')
                    ->selectRaw('COALESCE(unit_types.name, ?) as unit_type_name, count(*) as total', ['نامشخص'])
                    ->groupBy('unit_type_name')
                    ->pluck('total', 'unit_type_name')
                    ->toArray();

                $bySemat = (clone $persons)
                    ->whereNotNull('s_id')
                    ->join('semats', 'persons.s_id', '=', 'semats.id')
                    ->selectRaw('COALESCE(semats.name, ?) as semat_name, count(*) as total', ['نامشخص'])
                    ->groupBy('semat_name')
                    ->pluck('total', 'semat_name')
                    ->toArray();

                return [
                    'by_unit_type' => $byUnitType,
                    'by_semat' => $bySemat,
                ];
            }
        );

        return response()->json(['data' => $data]);
    }

    private function hrAnalyticsCacheKey(string $type, array $accessibleIds, int $months = 12): string
    {
        $version = Cache::get('hr_stats_version', 0);
        $scopeHash = md5(implode(',', $accessibleIds));

        return "hr:analytics:{$type}:v{$version}:m{$months}:{$scopeHash}";
    }
}
