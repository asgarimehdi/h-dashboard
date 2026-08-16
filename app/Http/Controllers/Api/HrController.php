<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HR API endpoints (Issue #223) — for the Flutter app and HR dashboard.
 * All queries are scoped to the caller's organizational units.
 */
class HrController extends Controller
{
    use PersianNormalizer;

    /**
     * GET /api/hr/org-chart — full org tree with personnel counts per unit.
     */
    public function orgChart(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $data = Cache::remember(
            $this->hrStatsCacheKey($accessibleIds, 'orgchart'),
            now()->addMinutes(10),
            function () use ($accessibleIds) {
                $units = Unit::whereIn('id', $accessibleIds)
                    ->withCount(['person as personnel_count'])
                    ->get();

                // Build nested tree from flat list (parent_id references)
                $byId = $units->keyBy('id');
                $tree = [];
                foreach ($units as $unit) {
                    if ($unit->parent_id && $byId->has($unit->parent_id)) {
                        $byId[$unit->parent_id]->children ??= [];
                        $byId[$unit->parent_id]->children[] = $unit;
                    } else {
                        $tree[] = $unit;
                    }
                }

                $format = function ($unit) use (&$format) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'parent_id' => $unit->parent_id,
                        'personnel_count' => $unit->personnel_count,
                        'children' => isset($unit->children)
                            ? collect($unit->children)->map($format)->values()
                            : [],
                    ];
                };

                return collect($tree)->map($format)->values();
            }
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/hr/org-chart/expandable — expandable org chart with initial_limit.
     * Returns first N root units; children loaded on-demand via loadSubtree.
     */
    public function orgChartExpandable(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $initialLimit = min((int) $request->get('initial_limit', 20), 100);

        $units = Unit::whereIn('id', $accessibleIds)
            ->withCount('person as personnel_count')
            ->with('unitType:id,name')
            ->orderBy('name')
            ->limit($initialLimit)
            ->get()
            ->map(fn (Unit $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'parent_id' => $u->parent_id,
                'unit_type' => $u->unitType?->name,
                'personnel_count' => $u->personnel_count,
                'has_children' => Unit::where('parent_id', $u->id)->exists(),
                'level' => 1,
            ]);

        return response()->json([
            'data' => $units->values(),
            'meta' => ['initial_limit' => $initialLimit],
        ]);
    }

    /**
     * GET /api/hr/org-chart/subtree/{unitId} — load entire subtree for a unit.
     */
    public function loadSubtree(Request $request, int $unitId): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($unitId, $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $units = Unit::whereIn('id', $accessibleIds)
            ->subtree($unitId)
            ->withCount('person as personnel_count')
            ->with('unitType:id,name')
            ->get();

        $byId = $units->keyBy('id');
        $root = $byId->get($unitId);

        if (! $root) {
            return response()->json(['data' => []]);
        }

        $children = $units->filter(fn (Unit $u) => $u->parent_id == $unitId);

        $format = function (Unit $unit) use (&$format, $byId) {
            $childUnits = $byId->filter(fn (Unit $u) => $u->parent_id == $unit->id);

            return [
                'id' => $unit->id,
                'name' => $unit->name,
                'parent_id' => $unit->parent_id,
                'unit_type' => $unit->unitType?->name,
                'personnel_count' => $unit->personnel_count,
                'has_children' => $childUnits->isNotEmpty(),
                'children' => $childUnits->map(fn (Unit $c) => $format($c))->values(),
            ];
        };

        $result = collect($children)->map(fn (Unit $c) => $format($c))->values();

        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/hr/stats — aggregated HR stats.
     */
    public function stats(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $data = Cache::remember(
            $this->hrStatsCacheKey($accessibleIds),
            now()->addMinutes(5),
            function () use ($accessibleIds) {
                if (DB::getDriverName() === 'pgsql') {
                    $idList = implode(',', array_map('intval', $accessibleIds));

                    // Single-pass aggregation (#436): one query computes all groupings via
                    // JSON_AGG/LEFT JOINs instead of 6 separate round-trips.
                    $row = DB::selectOne(
                        "SELECT
                            (SELECT count(*) FROM persons WHERE u_id IN ({$idList})) AS total,
                            (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.u_id::text), x.c), '{}')
                               FROM (SELECT p.u_id, u.name, count(*) AS c
                                     FROM persons p LEFT JOIN units u ON p.u_id = u.id
                                     WHERE p.u_id IN ({$idList}) GROUP BY p.u_id, u.name) x) AS by_unit,
                            (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.s_id::text), x.c), '{}')
                               FROM (SELECT p.s_id, s.name, count(*) AS c
                                     FROM persons p LEFT JOIN semats s ON p.s_id = s.id
                                     WHERE p.s_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.s_id, s.name) x) AS by_semat,
                            (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.t_id::text), x.c), '{}')
                               FROM (SELECT p.t_id, t.name, count(*) AS c
                                     FROM persons p LEFT JOIN tahsils t ON p.t_id = t.id
                                     WHERE p.t_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.t_id, t.name) x) AS by_tahsil,
                            (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.e_id::text), x.c), '{}')
                               FROM (SELECT p.e_id, e.name, count(*) AS c
                                     FROM persons p LEFT JOIN estekhdams e ON p.e_id = e.id
                                     WHERE p.e_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.e_id, e.name) x) AS by_estekhdam,
                            (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.r_id::text), x.c), '{}')
                               FROM (SELECT p.r_id, r.name, count(*) AS c
                                     FROM persons p LEFT JOIN radifs r ON p.r_id = r.id
                                     WHERE p.r_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.r_id, r.name) x) AS by_radif"
                    );

                    return [
                        'total_personnel' => (int) $row->total,
                        'by_unit' => json_decode($row->by_unit, true) ?? [],
                        'by_semat' => json_decode($row->by_semat, true) ?? [],
                        'by_tahsil' => json_decode($row->by_tahsil, true) ?? [],
                        'by_estekhdam' => json_decode($row->by_estekhdam, true) ?? [],
                        'by_radif' => json_decode($row->by_radif, true) ?? [],
                    ];
                }

                // Fallback for non-PgSQL drivers (e.g. SQLite in tests)
                $persons = Person::whereIn('persons.u_id', $accessibleIds)
                    ->leftJoin('units', 'persons.u_id', '=', 'units.id')
                    ->leftJoin('semats', 'persons.s_id', '=', 'semats.id')
                    ->leftJoin('tahsils', 'persons.t_id', '=', 'tahsils.id')
                    ->leftJoin('estekhdams', 'persons.e_id', '=', 'estekhdams.id')
                    ->leftJoin('radifs', 'persons.r_id', '=', 'radifs.id')
                    ->select([
                        'persons.u_id', 'persons.s_id', 'persons.t_id', 'persons.e_id', 'persons.r_id',
                        'units.name as unit_name', 'semats.name as semat_name',
                        'tahsils.name as tahsil_name', 'estekhdams.name as estekhdam_name',
                        'radifs.name as radif_name',
                    ])
                    ->get();

                $total = $persons->count();
                $group = fn ($rows, $key, $nameKey) => $rows->groupBy($key)
                    ->mapWithKeys(fn ($group, $k) => [$group->first()->{$nameKey} ?? (string) $k => $group->count()])
                    ->all();

                return [
                    'total_personnel' => $total,
                    'by_unit' => $group($persons, 'u_id', 'unit_name'),
                    'by_semat' => $group($persons, 'semat_name', 'semat_name'),
                    'by_tahsil' => $group($persons, 'tahsil_name', 'tahsil_name'),
                    'by_estekhdam' => $group($persons, 'estekhdam_name', 'estekhdam_name'),
                    'by_radif' => $group($persons, 'radif_name', 'radif_name'),
                ];
            }
        );

        return response()->json(['data' => $data]);
    }

    private function hrStatsCacheKey(array $accessibleIds, string $segment = 'stats'): string
    {
        $version = Cache::get('hr_stats_version', 0);
        $scopeHash = md5(implode(',', $accessibleIds));

        return "hr:{$segment}:v{$version}:{$scopeHash}";
    }

    /**
     * GET /api/hr/vacancies — units with no assigned semat holders.
     */
    public function vacancies(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $units = Unit::whereIn('id', $accessibleIds)
            ->withCount('person as personnel_count')
            ->get()
            ->filter(fn ($u) => $u->personnel_count === 0)
            ->values();

        return response()->json([
            'data' => $units->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'parent_id' => $u->parent_id,
            ]),
            'meta' => ['total' => $units->count()],
        ]);
    }

    /**
     * GET /api/hr/personnel — paginated personnel list with filters.
     */
    public function personnel(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $query = Person::leftJoin('units', 'persons.u_id', '=', 'units.id')
            ->leftJoin('semats', 'persons.s_id', '=', 'semats.id')
            ->leftJoin('tahsils', 'persons.t_id', '=', 'tahsils.id')
            ->leftJoin('estekhdams', 'persons.e_id', '=', 'estekhdams.id')
            ->leftJoin('radifs', 'persons.r_id', '=', 'radifs.id')
            ->whereIn('persons.u_id', $accessibleIds)
            ->select(
                'persons.*',
                'units.name as unit_name',
                'semats.name as semat_name',
                'tahsils.name as tahsil_name',
                'estekhdams.name as estekhdam_name',
                'radifs.name as radif_name',
            );

        $p = 'persons.';
        if ($request->filled('search')) {
            $s = self::normalizeForSearch($request->search);
            $query->where(function ($q) use ($s, $p) {
                $q->where($p.'n_code', 'LIKE', "%{$s}%")
                    ->orWhere($p.'f_name', 'LIKE', "%{$s}%")
                    ->orWhere($p.'l_name', 'LIKE', "%{$s}%");
            });
        }
        foreach (['unit_id' => 'u_id', 'semat_id' => 's_id', 'tahsil_id' => 't_id', 'estekhdam_id' => 'e_id', 'radif_id' => 'r_id', 'status' => 'status'] as $param => $col) {
            if ($request->filled($param)) {
                $query->where($p.$col, $request->{$param});
            }
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $persons = $query->paginate($perPage);

        // Rebuild the nested relation shape the clients expect (unit/semat/... objects)
        $items = $persons->getCollection()->map(function ($person) {
            $data = $person->toArray();
            $names = [
                'unit' => ['id' => $data['u_id'], 'name' => $data['unit_name']],
                'semat' => ['id' => $data['s_id'], 'name' => $data['semat_name']],
                'tahsil' => ['id' => $data['t_id'], 'name' => $data['tahsil_name']],
                'estekhdam' => ['id' => $data['e_id'], 'name' => $data['estekhdam_name']],
                'radif' => ['id' => $data['r_id'], 'name' => $data['radif_name']],
            ];
            foreach ([
                'unit_name', 'semat_name', 'tahsil_name', 'estekhdam_name', 'radif_name',
            ] as $alias) {
                unset($data[$alias]);
            }

            return array_merge($data, $names);
        });

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'current_page' => $persons->currentPage(),
                'last_page' => $persons->lastPage(),
                'per_page' => $persons->perPage(),
                'total' => $persons->total(),
            ],
        ]);
    }

    /**
     * GET /api/hr/personnel/{n_code} — personnel detail with full HR profile.
     */
    public function personDetail(Request $request, string $nCode): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $person = Person::with(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name'])
            ->where('n_code', $nCode)
            ->first();

        if (! $person || ! in_array($person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        return response()->json(['data' => $person]);
    }

    /**
     * GET /api/hr/analytics/headcount-trend — monthly headcount for last N months.
     */
    public function headcountTrend(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $months = min((int) $request->get('months', 12), 24);

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('headcount_trend', $accessibleIds, $months),
            now()->addMinutes(5),
            function () use ($accessibleIds, $months) {
                $since = now()->subMonths($months)->startOfMonth();

                // Fetch created_at dates and group by month in PHP for DB-agnostic behavior
                $records = Person::whereIn('u_id', $accessibleIds)
                    ->where('created_at', '>=', $since)
                    ->select('created_at')
                    ->get()
                    ->groupBy(fn ($p) => $p->created_at->format('Y-m'));

                return $records->map(fn ($group, $month) => [
                    'month' => $month,
                    'count' => $group->count(),
                ])->values();
            }
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/hr/analytics/vacancy-trend — monthly vacancy count (units with zero personnel).
     */
    public function vacancyTrend(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $months = min((int) $request->get('months', 12), 24);

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('vacancy_trend', $accessibleIds, $months),
            now()->addMinutes(5),
            function () use ($accessibleIds, $months) {
                if (DB::getDriverName() === 'pgsql') {
                    // PostgreSQL-optimized: single query with generate_series
                    $idList = '{' . implode(',', array_map('intval', $accessibleIds)) . '}';
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

                // Fallback for non-PgSQL (e.g., SQLite): preload all personnel and compute in PHP
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
                    $monthEnd = $monthDate->endOfMonth();

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
    public function staffingRatio(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $data = Cache::remember(
            $this->hrAnalyticsCacheKey('staffing_ratio', $accessibleIds),
            now()->addMinutes(5),
            function () use ($accessibleIds) {
                $persons = Person::whereIn('u_id', $accessibleIds);

                $byUnitType = (clone $persons)
                    ->join('units', 'persons.u_id', '=', 'units.id')
                    ->leftJoin('unit_types', 'units.unit_type_id', '=', 'unit_types.id')
                    ->selectRaw('unit_types.name as unit_type_name, count(*) as total')
                    ->groupBy('unit_types.name')
                    ->pluck('total', 'unit_type_name')
                    ->toArray();

                $bySemat = (clone $persons)
                    ->whereNotNull('s_id')
                    ->join('semats', 'persons.s_id', '=', 'semats.id')
                    ->selectRaw('semats.name as semat_name, count(*) as total')
                    ->groupBy('semats.name')
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
        return $this->hrStatsCacheKey($accessibleIds, "analytics:{$type}:m{$months}");
    }
}
