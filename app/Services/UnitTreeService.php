<?php

namespace App\Services;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared, access-scoped unit-tree queries.
 *
 * Extracted in issue #704 so the generic `unit.tree` Livewire component and
 * the HR org-chart API (OrgChartController) build their trees from ONE place
 * instead of duplicating the same queries.
 *
 * Every method takes the caller's accessible unit ids explicitly — the service
 * never resolves them itself, so callers stay in control of the scope (and
 * tests can pass any scope they want).
 *
 * NOTE ON QUERY SHAPE: every chain ends on an Eloquent-defined call
 * (`where()` / `whereKey()`). PHPStan resolves `whereIn()` through
 * Query\Builder's mixin, so anything Eloquent-only asked afterwards (`with`,
 * `withCount`, `get()` returning models) is reported as undefined — the scope
 * filters therefore live inside the trailing `where()` closure. Keep that
 * ordering: it produces byte-identical SQL.
 */
class UnitTreeService
{
    /**
     * Query for the tree roots: accessible units whose parent is NOT
     * accessible (or which have no parent at all).
     *
     * A user scoped to a child unit therefore still sees that unit as a root.
     *
     * @param  array<int>  $accessibleIds
     * @return Builder<Unit>
     */
    public function roots(array $accessibleIds): Builder
    {
        return Unit::query()
            ->with(['unitType'])
            ->where(function ($query) use ($accessibleIds) {
                $query->whereIn('id', $accessibleIds)
                    ->where(function ($nested) use ($accessibleIds) {
                        $nested->whereNull('parent_id')
                            ->orWhereNotIn('parent_id', $accessibleIds);
                    });
            });
    }

    /**
     * Query for the direct children of one unit, inside the accessible scope.
     *
     * @param  array<int>  $accessibleIds
     * @return Builder<Unit>
     */
    public function childrenOf(int $unitId, array $accessibleIds): Builder
    {
        return $this->childrenOfMany([$unitId], $accessibleIds);
    }

    /**
     * Query for the children of SEVERAL units in one round trip.
     *
     * The tree preloads a whole level at a time — issuing childrenOf() per
     * node would be an N+1 over every expanded unit on the page.
     *
     * @param  array<int>  $unitIds
     * @param  array<int>  $accessibleIds
     * @return Builder<Unit>
     */
    public function childrenOfMany(array $unitIds, array $accessibleIds): Builder
    {
        return Unit::query()
            ->with(['unitType'])
            ->where(function ($query) use ($unitIds, $accessibleIds) {
                $query->whereIn('parent_id', $unitIds)
                    ->whereIn('id', $accessibleIds);
            });
    }

    /**
     * Query for units whose name contains $term, inside the accessible scope.
     *
     * The `parent` relation is eager-loaded because callers walk the ancestor
     * chain of every match to expand it.
     *
     * @param  array<int>  $accessibleIds
     * @return Builder<Unit>
     */
    public function search(string $term, array $accessibleIds): Builder
    {
        return Unit::query()
            ->with(['parent'])
            ->where(function ($query) use ($term, $accessibleIds) {
                $query->where('name', 'LIKE', "%{$term}%")
                    ->whereIn('id', $accessibleIds);
            });
    }

    /**
     * Query for every accessible unit with its personnel count — the flat list
     * the org-chart API nests into JSON.
     *
     * @param  array<int>  $accessibleIds
     * @return Builder<Unit>
     */
    public function unitsInScope(array $accessibleIds): Builder
    {
        return Unit::query()
            ->withCount(['person as personnel_count'])
            ->whereKey($accessibleIds);
    }

    /**
     * Query for the whole subtree rooted at $unitId, inside the accessible scope.
     *
     * @param  array<int>  $accessibleIds
     * @return Builder<Unit>
     */
    public function subtree(int $unitId, array $accessibleIds): Builder
    {
        return Unit::subtree($unitId)
            ->whereKey($accessibleIds);
    }
}
