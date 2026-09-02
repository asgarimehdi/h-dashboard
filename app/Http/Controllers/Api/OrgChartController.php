<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Org-chart tree endpoints — extracted from HrController.
 */
class OrgChartController extends Controller
{
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

        $scopeHash = md5(implode(',', $accessibleIds));
        $version = Cache::get('hr_stats_version', 0);
        $cacheKey = "hr:expandable:v{$version}:{$scopeHash}:{$initialLimit}";

        $units = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessibleIds, $initialLimit) {
            return Unit::whereIn('id', $accessibleIds)
                ->withCount(['person as personnel_count', 'children as has_children'])
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
    public function loadSubtree(Request $request, int $unitId): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($unitId, $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $scopeHash = md5(implode(',', $accessibleIds));
        $result = app(CacheInvalidationServiceInterface::class)
            ->remember('unit_hierarchy', $scopeHash, function () use ($accessibleIds, $unitId) {
                $units = Unit::whereIn('id', $accessibleIds)
                    ->subtree($unitId)
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

    private function hrStatsCacheKey(array $accessibleIds, string $segment = 'stats'): string
    {
        $version = Cache::get('hr_stats_version', 0);
        $scopeHash = md5(implode(',', $accessibleIds));

        return "hr:{$segment}:v{$version}:{$scopeHash}";
    }
}
