<?php

namespace App\Services;

use App\Models\Unit;
use App\Traits\PersianNormalizer;
use Illuminate\Support\Collection;

/**
 * Tree queries for the unit hierarchy, consumed by the Livewire `unit.tree`
 * component — its only real consumer today.
 *
 * Issue #704 (Plan 30). Every method takes the caller's accessible unit IDs as
 * an argument rather than resolving them from the session: a future API caller
 * gets its scope from `UnitScopedRequest`, not `auth()`, and passing them in
 * keeps the service testable without a session.
 *
 * The `/api/hr/org-chart*` endpoints deliberately stay inline (comment in
 * OrgChartController): they interleave person counts and Flutter-specific
 * shaping into the tree walk, and HrApiTest gates their payload byte-identical.
 * Converging them onto this service is a separate, test-gated change.
 *
 * Extract the logic here rather than copy-pasting it — a second feature that
 * needs a unit tree (e.g. covered population per unit) plugs its own badge
 * view into `unit.tree` and gets all of this for free.
 *
 * Query-shape note (PHPStan level 6, no larastan in this repo): every chain
 * starts with `Unit::query()->with(...)`, where `query()` and `with()` are
 * declared on `Eloquent\Builder`. An IN-filter goes inside a `where(Closure)`
 * instead of a top-level `whereIn()`, because `whereIn` lives only on
 * `Query\Builder` and resolves through `@mixin` — which types the rest of the
 * chain as a query builder and makes `with()`/`get()` unreadable to PHPStan.
 */
class UnitTreeService
{
    use PersianNormalizer;

    /**
     * Shortest term (in CHARACTERS, not bytes) that triggers a search. The old
     * page gate was `strlen() > 2` — a byte count, so two Persian characters
     * (4 bytes) searched while one latin character did not. Two characters is
     * the closest deliberate equivalent; below it the result set is
     * effectively the whole scope, so the tree just resets its expansion.
     */
    private const MIN_SEARCH_LENGTH = 2;

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

        return Unit::query()
            ->with(['unitType'])
            ->where(function ($query) use ($accessibleIds) {
                $query->whereIn('id', $accessibleIds);
            })
            ->where(function ($query) use ($accessibleIds) {
                $query->whereNull('parent_id')
                    ->orWhereNotIn('parent_id', $accessibleIds);
            })
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

        return Unit::query()
            ->with(['unitType'])
            ->where('parent_id', $unitId)
            ->where(function ($query) use ($accessibleIds) {
                $query->whereIn('id', $accessibleIds);
            })
            ->get();
    }

    /**
     * Children of SEVERAL parents in ONE query — the batch counterpart of
     * `childrenOf()`, so a tree level paints with a single round trip
     * instead of one query per node (issue #722: a 1→6→36 tree cost ~44
     * queries on initial paint when each node asked for its own children).
     *
     * Scope contract mirrors `childrenOf()`: the requested parents are
     * intersected with the scope FIRST, so an out-of-scope parent yields no
     * children — exactly as if `childrenOf()` had been called for it (a child
     * can never be in scope while its parent is not, but the method must stay
     * safe on its own). Empty inputs return an empty collection without
     * touching the database.
     *
     * Parents with no (in-scope) children get NO key in the result — callers
     * read with `->get($parentId, collect())`.
     *
     * @param  array<int>  $unitIds
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Collection<int, Unit>> parent id => its children
     */
    public function childrenOfMany(array $unitIds, array $accessibleIds): Collection
    {
        $parentIds = array_values(array_intersect($unitIds, $accessibleIds));

        if ($parentIds === []) {
            return collect();
        }

        return Unit::query()
            ->with(['unitType'])
            ->where(function ($query) use ($parentIds, $accessibleIds) {
                $query->whereIn('parent_id', $parentIds)
                    ->whereIn('id', $accessibleIds);
            })
            ->get()
            ->groupBy('parent_id');
    }

    /**
     * Whether a unit has at least one child the caller may see.
     *
     * Used by the tree's node template to decide whether to draw a toggle for
     * a unit whose children are not loaded yet. Existence only — the node
     * never needs the rows themselves, and this must not degrade into a
     * per-node full fetch on every render.
     *
     * @param  array<int>  $accessibleIds
     */
    public function hasChildren(int $unitId, array $accessibleIds): bool
    {
        if ($accessibleIds === [] || ! in_array($unitId, $accessibleIds, true)) {
            return false;
        }

        return Unit::query()
            ->where(function ($query) use ($unitId, $accessibleIds) {
                $query->where('parent_id', $unitId)
                    ->whereIn('id', $accessibleIds);
            })
            ->exists();
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

        return Unit::query()
            ->with(['unitType', 'parent'])
            ->where(function ($query) use ($accessibleIds, $folded) {
                $query->whereIn('id', $accessibleIds);
                $query->whereRaw(self::foldSeparatorsSql('name').' LIKE ?', ["%{$folded}%"]);
            })
            ->get();
    }

    /**
     * The full ancestor chain of a unit, ordered root first, excluding the unit
     * itself. Stops at the scope boundary, so a chain never names a unit the
     * caller is not allowed to see.
     *
     * The walk keeps its own visited set: a `parent_id` cycle would otherwise
     * loop forever, and this runs per search match. Same guard the units
     * export's `buildHierarchy()` uses (issue #702). The chain is walked
     * through the `parent` relation rather than a per-hop `Unit::find()`,
     * matching how the original org chart walked it.
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function ancestorChain(Unit $unit, array $accessibleIds): Collection
    {
        $chain = collect();
        $visited = [(int) $unit->id => true];
        $current = $unit;

        while ($current->parent_id) {
            $parentId = (int) $current->parent_id;

            if (isset($visited[$parentId]) || ! in_array($parentId, $accessibleIds, true)) {
                break;
            }

            $parent = $current->parent;

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
