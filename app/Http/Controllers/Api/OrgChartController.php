<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Unit;
use App\Rules\PerPage;
use App\Services\CacheInvalidationServiceInterface;
use App\Support\ScopeCacheKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Org-chart tree endpoints — extracted from HrController.
 */
class OrgChartController extends Controller
{
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
                $units = Unit::whereIn('id', $accessibleIds)
                    ->withCount(['person as personnel_count'])
                    ->get();

                // Build nested tree from flat list (parent_id references).
                // The else-branch below is the same predicate UnitTreeService::roots()
                // applies (parent is null, or the parent is out of scope) — see
                // issue #704. It stays inline here deliberately: the loop already
                // holds every accessible unit in $byId, so calling roots() would
                // re-query the same rows to learn something this loop can read off
                // directly. The Livewire `unit.tree` component, which does NOT
                // already have the flat list in hand, uses the service.
                // Children are collected in a SEPARATE map keyed by parent id —
                // never written onto the models. `units` has no `children` column,
                // so `$unit->children` resolves to the real `hasMany` relation
                // (Unit.php:106-108); reading it with `??=` lazy-loads every real
                // child of the parent from the database and then the loop appended
                // on top, duplicating every subtree multiplicatively (#933).
                // See UnitsExportController::buildHierarchy() for the same shape.
                $byId = $units->keyBy('id');
                $childrenByParent = [];
                $tree = [];
                foreach ($units as $unit) {
                    if ($unit->parent_id && $byId->has($unit->parent_id)) {
                        $childrenByParent[$unit->parent_id][] = $unit;
                    } else {
                        $tree[] = $unit;
                    }
                }

                $format = function ($unit) use (&$format, $childrenByParent) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'parent_id' => $unit->parent_id,
                        'personnel_count' => $unit->personnel_count,
                        'children' => collect($childrenByParent[$unit->id] ?? [])->map($format)->values(),
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
        // Issue #894: see UnitController::index(). The more dangerous site of the two:
        // this one calls `->limit()` directly, and the value is hashed into the
        // cache key, so every distinct negative minted a fresh full-scope entry.
        $request->validate(['initial_limit' => ['sometimes', new PerPage(100)]]);
        $initialLimit = min((int) $request->get('initial_limit', 20), 100);

        $scopeHash = ScopeCacheKey::hash($accessibleIds);
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
    public function loadSubtree(UnitScopedRequest $request, int $unitId): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($unitId, $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $scopeHash = ScopeCacheKey::hash($accessibleIds);
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

                // #1022: collect children in a single pass keyed by parent id —
                // the same pattern orgChart() uses above. The previous code
                // re-filtered the ENTIRE subtree collection once per visited node
                // (O(n^2) for n nodes); `$byId` was a map, but it was only ever
                // traversed as a list. Children are never written onto the models:
                // `units` has no `children` column, so `$unit->children` is the real
                // `hasMany` relation; reading it duplicates every subtree
                // multiplicatively (#933).
                //
                // `each()` rather than `foreach`, and plain arrays rather than
                // `collect()->map()`: `$units` is untyped here, so a bare `foreach`
                // leaves `$unit` mixed and `collect()` on that mixed-valued array is
                // what produced the unresolvable-type PHPStan errors this refactor
                // removes. `children` is JSON-identical either way — a Collection and
                // a 0-indexed array both encode as a JSON array.
                $childrenByParent = [];
                $units->each(function (Unit $unit) use (&$childrenByParent, $byId): void {
                    if ($unit->parent_id && $byId->has($unit->parent_id)) {
                        $childrenByParent[$unit->parent_id][] = $unit;
                    }
                });

                $format = function (Unit $unit) use (&$format, $childrenByParent): array {
                    $children = [];
                    foreach ($childrenByParent[$unit->id] ?? [] as $child) {
                        $children[] = $format($child);
                    }

                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'parent_id' => $unit->parent_id,
                        'unit_type' => $unit->unitType?->name,
                        'personnel_count' => $unit->personnel_count,
                        'has_children' => $children !== [],
                        'children' => $children,
                    ];
                };

                $result = [];
                foreach ($childrenByParent[$unitId] ?? [] as $child) {
                    $result[] = $format($child);
                }

                return $result;
            }, 10, ['subtree' => $unitId]);

        return response()->json(['data' => $result]);
    }

    private function hrStatsCacheKey(array $accessibleIds, string $segment = 'stats'): string
    {
        $version = Cache::get('hr_stats_version', 0);
        $scopeHash = ScopeCacheKey::hash($accessibleIds);

        return "hr:{$segment}:v{$version}:{$scopeHash}";
    }
}
