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
                $persons = Person::whereIn('u_id', $accessibleIds);

                $total = (clone $persons)->count();

                // Use JOIN to fetch names alongside aggregates — removes dead with() calls and N+1
                $byUnit = (clone $persons)
                    ->leftJoin('units', 'persons.u_id', '=', 'units.id')
                    ->selectRaw('persons.u_id, units.name as unit_name, count(*) as total')
                    ->groupBy('persons.u_id', 'units.name')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->unit_name ?? $r->u_id => $r->total])
                    ->toArray();

                $bySemat = (clone $persons)
                    ->whereNotNull('s_id')
                    ->leftJoin('semats', 'persons.s_id', '=', 'semats.id')
                    ->selectRaw('persons.s_id, semats.name as semat_name, count(*) as total')
                    ->groupBy('persons.s_id', 'semats.name')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->semat_name ?? $r->s_id => $r->total])
                    ->toArray();

                $byTahsil = (clone $persons)
                    ->whereNotNull('t_id')
                    ->leftJoin('tahsils', 'persons.t_id', '=', 'tahsils.id')
                    ->selectRaw('persons.t_id, tahsils.name as tahsil_name, count(*) as total')
                    ->groupBy('persons.t_id', 'tahsils.name')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->tahsil_name ?? $r->t_id => $r->total])
                    ->toArray();

                $byEstekhdam = (clone $persons)
                    ->whereNotNull('e_id')
                    ->leftJoin('estekhdams', 'persons.e_id', '=', 'estekhdams.id')
                    ->selectRaw('persons.e_id, estekhdams.name as estekhdam_name, count(*) as total')
                    ->groupBy('persons.e_id', 'estekhdams.name')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->estekhdam_name ?? $r->e_id => $r->total])
                    ->toArray();

                $byRadif = (clone $persons)
                    ->whereNotNull('r_id')
                    ->leftJoin('radifs', 'persons.r_id', '=', 'radifs.id')
                    ->selectRaw('persons.r_id, radifs.name as radif_name, count(*) as total')
                    ->groupBy('persons.r_id', 'radifs.name')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->radif_name ?? $r->r_id => $r->total])
                    ->toArray();

                return [
                    'total_personnel' => $total,
                    'by_unit' => $byUnit,
                    'by_semat' => $bySemat,
                    'by_tahsil' => $byTahsil,
                    'by_estekhdam' => $byEstekhdam,
                    'by_radif' => $byRadif,
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
