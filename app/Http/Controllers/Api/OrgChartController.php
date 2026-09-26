<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Unit;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\UnitTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Org-chart tree endpoints — extracted from HrController.
 *
 * Every tree query is delegated to UnitTreeService (#704). The JSON produced
 * here is consumed by the Flutter app, so the shape must stay byte-identical
 * — HrApiTest pins it and must pass unmodified.
 */
class OrgChartController extends Controller
{
    public function __construct(private readonly UnitTreeService $treeService) {}

    /**
     * GET /api/hr/org-chart — full org tree with personnel counts per unit.
     */
    public function orgChart(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        $data = Cache::remember(
            $this->hrStatsCacheKey($accessibleIds, 'orgchart'),
            now()->addMinutes(10),
            function () use ($accessibleIds) {
                $units = $this->treeService->unitsInScope($accessibleIds)->get();

                // Build nested tree from flat list (parent_id references).
                // Children are kept in a local map instead of being written
                // onto the models — `$children` is a read-only relation and
                // mutating it is both wrong and PHPStan-hostile.
                $byId = $units->keyBy('id');
                $tree = [];
                $childrenOf = [];
                foreach ($units as $unit) {
                    if ($unit->parent_id && $byId->has($unit->parent_id)) {
                        $childrenOf[$unit->parent_id][] = $unit;
                    } else {
                        $tree[] = $unit;
                    }
                }

                $format = function ($unit) use (&$format, $childrenOf) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'parent_id' => $unit->parent_id,
                        'personnel_count' => $unit->personnel_count,
                        'children' => isset($childrenOf[$unit->id])
                            ? collect($childrenOf[$unit->id])->map($format)->values()
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
    public function orgChartExpandable(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $initialLimit = min((int) $request->get('initial_limit', 20), 100);

        $scopeHash = md5(implode(',', $accessibleIds));
        $version = Cache::get('hr_stats_version', 0);
        $cacheKey = "hr:expandable:v{$version}:{$scopeHash}:{$initialLimit}";

        $units = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessibleIds, $initialLimit) {
            return $this->treeService->unitsInScope($accessibleIds)
                ->withCount(['children as has_children'])
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
                    'has_children' => $u->has_children > 0,
                    'level' => 1,
                ]);
        });

        return response()->json([
            'data' => $units->values(),
            'meta' => ['initial_limit' => $initialLimit],
        ]);
    }

    /**
     * GET /api/hr/org-chart/subtree/{unitId} — load entire subtree for a unit.
     */
    public function loadSubtree(UnitScopedRequest $request, int $unitId): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($unitId, $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $scopeHash = md5(implode(',', $accessibleIds));
        $result = app(CacheInvalidationServiceInterface::class)
            ->remember('unit_hierarchy', $scopeHash, function () use ($accessibleIds, $unitId) {
                $units = $this->treeService->subtree($unitId, $accessibleIds)
                    ->withCount('person as personnel_count')
                    ->with('unitType:id,name')
                    ->get();

                $byId = $units->keyBy('id');
                $root = $byId->get($unitId);

                if (! $root) {
                    return [];
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

                return collect($children)->map(fn (Unit $c) => $format($c))->values();
            }, 10, ['subtree' => $unitId]);

        return response()->json(['data' => $result]);
    }

    /**
     * @param  array<int>  $accessibleIds
     */
    private function hrStatsCacheKey(array $accessibleIds, string $segment = 'stats'): string
    {
        $version = Cache::get('hr_stats_version', 0);
        $scopeHash = md5(implode(',', $accessibleIds));

        return "hr:{$segment}:v{$version}:{$scopeHash}";
    }
}
