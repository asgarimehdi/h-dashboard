<?php

namespace App\Services;

use App\Models\Unit;
use App\Traits\PersianNormalizer;
use Illuminate\Support\Collection;

/**
 * Tree queries for the unit hierarchy, shared by the Livewire `unit.tree`
 * component and the `/api/hr/org-chart*` endpoints.
 *
 * Issue #704 (Plan 30). Every method takes the caller's accessible unit IDs as
 * an argument rather than resolving them from the session: the API controller
 * gets its scope from `UnitScopedRequest`, not `auth()`, and passing them in
 * keeps the service testable without a session.
 *
 * Extract the logic here rather than copy-pasting it — a second feature that
 * needs a unit tree (e.g. covered population per unit) plugs its own badge
 * view into `unit.tree` and gets all of this for free.
 */
class UnitTreeService
{
    use PersianNormalizer;

    /**
     * Shortest term that triggers a search. Below this the result set is
     * effectively the whole scope, so the tree just resets its expansion.
     */
    private const MIN_SEARCH_LENGTH = 3;

    /**
     * The roots of an access-scoped tree: accessible units whose parent is
     * either absent or OUTSIDE the scope.
     *
     * Scoping roots this way is what lets a user who can see a child unit but
     * not its parent still see that child — otherwise their tree renders empty
     * because the real top of the tree is hidden from them.
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function roots(array $accessibleIds): Collection
    {
        if ($accessibleIds === []) {
            return collect();
        }

        return Unit::whereIn('id', $accessibleIds)
            ->where(function ($query) use ($accessibleIds) {
                $query->whereNull('parent_id')
                    ->orWhereNotIn('parent_id', $accessibleIds);
            })
            ->with(['unitType'])
            ->get();
    }

    /**
     * Direct children of a unit, restricted to the scope.
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function childrenOf(int $unitId, array $accessibleIds): Collection
    {
        if ($accessibleIds === [] || ! in_array($unitId, $accessibleIds, true)) {
            return collect();
        }

        return Unit::where('parent_id', $unitId)
            ->whereIn('id', $accessibleIds)
            ->with(['unitType'])
            ->get();
    }

    /**
     * Scope-restricted units whose name matches the term.
     *
     * Both sides of the comparison are folded: the term through
     * `foldedTerm()`, the column through `foldSeparatorsSql()`. Normalizing the
     * term alone is not enough — the stored text keeps its original code
     * points, so "حرفه‌ای" (with a ZWNJ) stays invisible to a search for
     * "حرفه ای" and "آموزش" to a search for "اموزش" (issue #705).
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function search(string $term, array $accessibleIds): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH || $accessibleIds === []) {
            return collect();
        }

        $folded = self::foldedTerm($term);

        return Unit::whereIn('id', $accessibleIds)
            ->whereRaw(self::foldSeparatorsSql('name').' LIKE ?', ["%{$folded}%"])
            ->with(['unitType', 'parent'])
            ->get();
    }

    /**
     * The full ancestor chain of a unit, ordered root first, excluding the unit
     * itself. Stops at the scope boundary, so a chain never names a unit the
     * caller is not allowed to see.
     *
     * The walk keeps its own visited set: a `parent_id` cycle would otherwise
     * loop forever, and this runs per search match. Same guard the units
     * export's `buildHierarchy()` uses (issue #702).
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function ancestorChain(Unit $unit, array $accessibleIds): Collection
    {
        $chain = collect();
        $visited = [(int) $unit->id => true];
        $current = $unit;

        while ($current && $current->parent_id) {
            $parentId = (int) $current->parent_id;

            if (isset($visited[$parentId]) || ! in_array($parentId, $accessibleIds, true)) {
                break;
            }

            $parent = Unit::find($parentId);

            if (! $parent) {
                break;
            }

            $chain->prepend($parent);
            $visited[$parentId] = true;
            $current = $parent;
        }

        return $chain;
    }
}
