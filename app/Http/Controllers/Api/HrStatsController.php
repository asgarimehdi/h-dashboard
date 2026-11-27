<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Person;
use App\Models\Unit;
use App\Rules\PerPage;
use App\Services\CacheInvalidationServiceInterface;
use App\Support\ScopeCacheKey;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * HR aggregate stats — extracted from HrController.
 */
class HrStatsController extends Controller
{
    use PersianNormalizer;

    /** Columns every `hr:read` + `view_hr_dashboard` caller may see. */
    private const SUMMARY_COLUMNS = [
        'id', 'n_code', 'f_name', 'l_name',
        'u_id', 's_id', 't_id', 'e_id', 'r_id',
        'status', 'created_at', 'updated_at',
    ];

    /** Full HR profile columns — only for `kargozini|manage_personnel` readers. */
    private const PII_COLUMNS = ['phone', 'birth_date', 'hire_date'];

    /**
     * Issue #1802: `view_hr_dashboard` alone is a dashboard grant, not a
     * row-level PII grant. An explicit allowlist (rather than a wildcard
     * select) also keeps new and generated columns out of the payload.
     *
     * @return list<string>
     */
    private function personColumns(UnitScopedRequest $request): array
    {
        $columns = self::SUMMARY_COLUMNS;

        // Fail-closed: canAny() returns false for an unknown permission, so a
        // deployment missing these permissions leaks no PII.
        if ($request->user()?->canAny(['kargozini', 'manage_personnel'])) {
            $columns = array_merge($columns, self::PII_COLUMNS);
        }

        return $columns;
    }

    /**
     * GET /api/hr/stats — aggregated HR stats.
     */
    public function stats(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        $scopeHash = ScopeCacheKey::hash($accessibleIds);

        $data = app(CacheInvalidationServiceInterface::class)->remember(
            'hr_stats',
            $scopeHash,
            function () use ($accessibleIds) {
                $idArray = '{'.implode(',', array_map('intval', $accessibleIds)).'}';

                $row = DB::selectOne(
                    "SELECT
                        (SELECT count(*) FROM persons WHERE u_id = ANY(?)) AS total,
                        (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.u_id::text), x.c), '{}')
                           FROM (SELECT p.u_id, u.name, count(*) AS c
                                 FROM persons p LEFT JOIN units u ON p.u_id = u.id
                                 WHERE p.u_id = ANY(?) GROUP BY p.u_id, u.name) x) AS by_unit,
                        (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.s_id::text), x.c), '{}')
                           FROM (SELECT p.s_id, s.name, count(*) AS c
                                 FROM persons p LEFT JOIN semats s ON p.s_id = s.id
                                 WHERE p.s_id IS NOT NULL AND p.u_id = ANY(?) GROUP BY p.s_id, s.name) x) AS by_semat,
                        (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.t_id::text), x.c), '{}')
                           FROM (SELECT p.t_id, t.name, count(*) AS c
                                 FROM persons p LEFT JOIN tahsils t ON p.t_id = t.id
                                 WHERE p.t_id IS NOT NULL AND p.u_id = ANY(?) GROUP BY p.t_id, t.name) x) AS by_tahsil,
                        (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.e_id::text), x.c), '{}')
                           FROM (SELECT p.e_id, e.name, count(*) AS c
                                 FROM persons p LEFT JOIN estekhdams e ON p.e_id = e.id
                                 WHERE p.e_id IS NOT NULL AND p.u_id = ANY(?) GROUP BY p.e_id, e.name) x) AS by_estekhdam,
                        (SELECT coalesce(jsonb_object_agg(coalesce(x.name, x.r_id::text), x.c), '{}')
                           FROM (SELECT p.r_id, r.name, count(*) AS c
                                 FROM persons p LEFT JOIN radifs r ON p.r_id = r.id
                                 WHERE p.r_id IS NOT NULL AND p.u_id = ANY(?) GROUP BY p.r_id, r.name) x) AS by_radif",
                    [$idArray, $idArray, $idArray, $idArray, $idArray, $idArray]
                );

                return [
                    'total_personnel' => (int) $row->total,
                    'by_unit' => json_decode($row->by_unit, true) ?? [],
                    'by_semat' => json_decode($row->by_semat, true) ?? [],
                    'by_tahsil' => json_decode($row->by_tahsil, true) ?? [],
                    'by_estekhdam' => json_decode($row->by_estekhdam, true) ?? [],
                    'by_radif' => json_decode($row->by_radif, true) ?? [],
                ];
            },
            5,
            ['segment' => 'stats']
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/hr/vacancies — units with no assigned semat holders.
     */
    public function vacancies(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        $scopeHash = ScopeCacheKey::hash($accessibleIds);

        // Issue #1888: hydrate only the three columns the response needs, cap the
        // rows in SQL, and keep `meta.total` as the full count of empty units so
        // its meaning does not change. The Livewire twin does the same at a
        // widget-sized cap (10); this is a data endpoint, so the repo-wide API
        // ceiling of 100 (PerPage) applies instead. `units.name` is not unique,
        // hence the `id` tie-breaker for a deterministic cap boundary.
        /** @var array{total: int, units: array<int, array{id: int, name: string, parent_id: int|null}>} $cached */
        $cached = app(CacheInvalidationServiceInterface::class)->remember('hr_stats', $scopeHash, function () use ($accessibleIds) {
            $base = Unit::query()
                ->whereIn('id', $accessibleIds)
                ->whereDoesntHave('person');

            $total = (clone $base)->count();

            $units = $base
                ->select(['id', 'name', 'parent_id'])
                ->orderBy('name')
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->map(fn (Unit $unit): array => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'parent_id' => $unit->parent_id,
                ])
                ->values()
                ->all();

            return ['total' => $total, 'units' => $units];
        }, 10, ['segment' => 'vacancies_v2']);

        return response()->json([
            'data' => $cached['units'],
            'meta' => ['total' => $cached['total']],
        ]);
    }

    /**
     * GET /api/hr/personnel — paginated personnel list with filters.
     */
    public function personnel(UnitScopedRequest $request): JsonResponse
    {
        // Issue #1811: malformed list/filter params must be a 422, never a
        // 500 from a typed helper or a typed column. Validate before any
        // query use — the same pattern as PersonController (#1777).
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string'],
            'unit_id' => ['sometimes', 'nullable', 'integer'],
            'semat_id' => ['sometimes', 'nullable', 'integer'],
            'tahsil_id' => ['sometimes', 'nullable', 'integer'],
            'estekhdam_id' => ['sometimes', 'nullable', 'integer'],
            'radif_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive,retired'],
        ]);

        $accessibleIds = $request->accessibleIds();

        $query = Person::leftJoin('units', 'persons.u_id', '=', 'units.id')
            ->leftJoin('semats', 'persons.s_id', '=', 'semats.id')
            ->leftJoin('tahsils', 'persons.t_id', '=', 'tahsils.id')
            ->leftJoin('estekhdams', 'persons.e_id', '=', 'estekhdams.id')
            ->leftJoin('radifs', 'persons.r_id', '=', 'radifs.id')
            ->whereIn('persons.u_id', $accessibleIds)
            ->select([
                ...array_map(fn (string $column): string => 'persons.'.$column, $this->personColumns($request)),
                'units.name as unit_name',
                'semats.name as semat_name',
                'tahsils.name as tahsil_name',
                'estekhdams.name as estekhdam_name',
                'radifs.name as radif_name',
            ]);

        $p = 'persons.';
        if ($request->filled('search')) {
            $s = self::normalizeForQuery((string) $validated['search']);
            $query->where(function ($q) use ($s, $p) {
                $q->where($p.'n_code', 'LIKE', "%{$s}%")
                    ->orWhere($p.'f_name', 'LIKE', "%{$s}%")
                    ->orWhere($p.'l_name', 'LIKE', "%{$s}%")
                    // Issue #1009: `||` (NOT `concat()`) so the
                    // `persons_fullname_trgm_idx` expression index applies.
                    ->orWhereRaw("({$p}f_name || ' ' || {$p}l_name) LIKE ?", ["%{$s}%"]);
            });
        }
        foreach (['unit_id' => 'u_id', 'semat_id' => 's_id', 'tahsil_id' => 't_id', 'estekhdam_id' => 'e_id', 'radif_id' => 'r_id', 'status' => 'status'] as $param => $col) {
            if ($request->filled($param)) {
                $query->where($p.$col, $validated[$param]);
            }
        }

        // Issue #894: see UnitController::index(). 422 instead of a silent one-sided
        // clamp; `min()` kept as defence in depth.
        $request->validate(['per_page' => ['sometimes', new PerPage(100)]]);
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
     * GET /api/hr/personnel/{n_code} — personnel detail; PII fields only for
     * `kargozini|manage_personnel` readers (#1802).
     */
    public function personDetail(UnitScopedRequest $request, string $nCode): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        $person = Person::query()
            ->select($this->personColumns($request))
            ->with(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name'])
            ->where('n_code', $nCode)
            ->first();

        if (! $person || ! in_array($person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        return response()->json(['data' => $person]);
    }
}
