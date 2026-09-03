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
 * HR aggregate stats — extracted from HrController.
 */
class HrStatsController extends Controller
{
    use PersianNormalizer;

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

    /**
     * GET /api/hr/vacancies — units with no assigned semat holders.
     */
    public function vacancies(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $scopeHash = md5(implode(',', $accessibleIds));
        $version = Cache::get('hr_stats_version', 0);
        $cacheKey = "hr:vacancies:v{$version}:{$scopeHash}";

        $units = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessibleIds) {
            return Unit::whereIn('id', $accessibleIds)
                ->whereDoesntHave('person')
                ->get();
        });

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
                    ->orWhere($p.'l_name', 'LIKE', "%{$s}%")
                    ->orWhereRaw("CONCAT({$p}f_name, ' ', {$p}l_name) LIKE ?", ["%{$s}%"]);
            });
        }
        foreach (['unit_id' => 'u_id', 'semat_id' => 's_id', 'tahsil_id' => 't_id', 'estekhdam_id' => 'e_id', 'radif_id' => 'r_id', 'status' => 'status'] as $param => $col) {
            if ($request->filled($param)) {
                $query->where($p.$col, $request->{$param});
            }
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $persons = $query->paginate($perPage);

        // Rebuild the nested relation shape the clients expect
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

    private function hrStatsCacheKey(array $accessibleIds, string $segment = 'stats'): string
    {
        $version = Cache::get('hr_stats_version', 0);
        $scopeHash = md5(implode(',', $accessibleIds));

        return "hr:{$segment}:v{$version}:{$scopeHash}";
    }
}
