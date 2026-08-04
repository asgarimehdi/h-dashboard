<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json([
            'data' => collect($tree)->map($format)->values(),
        ]);
    }

    /**
     * GET /api/hr/stats — aggregated HR stats.
     */
    public function stats(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        $persons = Person::whereIn('u_id', $accessibleIds);

        $total = (clone $persons)->count();
        $byUnit = (clone $persons)->selectRaw('u_id, count(*) as total')
            ->groupBy('u_id')->with(['unit:id,name'])->get();
        $bySemat = (clone $persons)->selectRaw('s_id, count(*) as total')
            ->whereNotNull('s_id')->groupBy('s_id')->with(['semat:id,name'])->get();
        $byTahsil = (clone $persons)->selectRaw('t_id, count(*) as total')
            ->whereNotNull('t_id')->groupBy('t_id')->with(['tahsil:id,name'])->get();
        $byEstekhdam = (clone $persons)->selectRaw('e_id, count(*) as total')
            ->whereNotNull('e_id')->groupBy('e_id')->with(['estekhdam:id,name'])->get();
        $byRadif = (clone $persons)->selectRaw('r_id, count(*) as total')
            ->whereNotNull('r_id')->groupBy('r_id')->with(['radif:id,name'])->get();

        return response()->json([
            'data' => [
                'total_personnel' => $total,
                'by_unit' => $byUnit,
                'by_semat' => $bySemat,
                'by_tahsil' => $byTahsil,
                'by_estekhdam' => $byEstekhdam,
                'by_radif' => $byRadif,
            ],
        ]);
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
