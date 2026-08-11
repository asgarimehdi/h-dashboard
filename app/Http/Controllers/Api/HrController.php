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
     * GET /api/hr/stats — aggregated HR stats.
     */
    public function stats(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $data = Cache::remember(
            $this->hrStatsCacheKey($accessibleIds),
            now()->addMinutes(5),
            function () use ($accessibleIds) {
                $idList = implode(',', array_map('intval', $accessibleIds));

                // Single-pass aggregation (#436): one query computes all groupings via
                // JSON_AGG/LEFT JOINs instead of 6 separate round-trips.
                $row = DB::selectOne(
                    "SELECT
                        (SELECT count(*) FROM persons WHERE u_id IN ({$idList})) AS total,
                        (SELECT coalesce(jsonb_object_agg(coalesce(u.name, p.u_id::text), c), '{}')
                           FROM (SELECT p.u_id, u.name, count(*) AS c
                                 FROM persons p LEFT JOIN units u ON p.u_id = u.id
                                 WHERE p.u_id IN ({$idList}) GROUP BY p.u_id, u.name) x
                           LEFT JOIN persons p ON true
                           LEFT JOIN units u ON p.u_id = u.id) AS by_unit,
                        (SELECT coalesce(jsonb_object_agg(coalesce(s.name, p.s_id::text), c), '{}')
                           FROM (SELECT p.s_id, s.name, count(*) AS c
                                 FROM persons p LEFT JOIN semats s ON p.s_id = s.id
                                 WHERE p.s_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.s_id, s.name) x
                           LEFT JOIN persons p ON true
                           LEFT JOIN semats s ON p.s_id = s.id) AS by_semat,
                        (SELECT coalesce(jsonb_object_agg(coalesce(t.name, p.t_id::text), c), '{}')
                           FROM (SELECT p.t_id, t.name, count(*) AS c
                                 FROM persons p LEFT JOIN tahsils t ON p.t_id = t.id
                                 WHERE p.t_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.t_id, t.name) x
                           LEFT JOIN persons p ON true
                           LEFT JOIN tahsils t ON p.t_id = t.id) AS by_tahsil,
                        (SELECT coalesce(jsonb_object_agg(coalesce(e.name, p.e_id::text), c), '{}')
                           FROM (SELECT p.e_id, e.name, count(*) AS c
                                 FROM persons p LEFT JOIN estekhdams e ON p.e_id = e.id
                                 WHERE p.e_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.e_id, e.name) x
                           LEFT JOIN persons p ON true
                           LEFT JOIN estekhdams e ON p.e_id = e.id) AS by_estekhdam,
                        (SELECT coalesce(jsonb_object_agg(coalesce(r.name, p.r_id::text), c), '{}')
                           FROM (SELECT p.r_id, r.name, count(*) AS c
                                 FROM persons p LEFT JOIN radifs r ON p.r_id = r.id
                                 WHERE p.r_id IS NOT NULL AND p.u_id IN ({$idList}) GROUP BY p.r_id, r.name) x
                           LEFT JOIN persons p ON true
                           LEFT JOIN radifs r ON p.r_id = r.id) AS by_radif"
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

        $query = Person::whereIn('u_id', $accessibleIds)
            ->with(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name']);

        if ($request->filled('search')) {
            $s = self::normalizeForSearch($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('n_code', 'LIKE', "%{$s}%")
                  ->orWhere('f_name', 'LIKE', "%{$s}%")
                  ->orWhere('l_name', 'LIKE', "%{$s}%");
            });
        }
        foreach (['unit_id' => 'u_id', 'semat_id' => 's_id', 'tahsil_id' => 't_id', 'estekhdam_id' => 'e_id', 'radif_id' => 'r_id', 'status' => 'status'] as $param => $col) {
            if ($request->filled($param)) {
                $query->where($col, $request->{$param});
            }
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $persons = $query->paginate($perPage);

        return response()->json([
            'data' => $persons->items(),
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
}
