<?php

namespace App\Services;

use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Shared tree queries for both the Livewire unit-tree component
 * and the OrgChartController (Flutter API).
 *
 * Plug-in contract for the Livewire component:
 *   Input:  badgeView (string) — Blade view rendered per node, receives $unit
 *           title, searchPlaceholder — page chrome strings
 *   Output: unit-selected event (int id) — fired on node click
 */
class UnitTreeService
{
    /**
     * All accessible units with their personnel count, in one query.
     *
     * Callers nest these into a tree themselves (parent_id references) —
     * the API does this; the Livewire component uses roots()/childrenOf()
     * instead so it can lazy-load.
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function allScoped(array $accessibleIds): Collection
    {
        return Unit::whereIn('id', $accessibleIds)
            ->withCount(['person as personnel_count'])
            ->with(['unitType'])
            ->get();
    }

    /**
     * Root units = accessible units whose parent is NOT accessible (or has no parent).
     * This way a user with access to a child unit (but not its parent) still sees it.
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function roots(array $accessibleIds): Collection
    {
        return Unit::whereIn('id', $accessibleIds)
            ->where(function ($q) use ($accessibleIds) {
                $q->whereNull('parent_id')
                    ->orWhereNotIn('parent_id', $accessibleIds);
            })
            ->withCount(['person as personnel_count'])
            ->with(['unitType'])
            ->get();
    }

    /**
     * Load children of a specific unit, scoped to accessible IDs.
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, Unit>
     */
    public function childrenOf(int $unitId, array $accessibleIds): Collection
    {
        if (! in_array($unitId, $accessibleIds)) {
            return collect();
        }

        return Unit::where('parent_id', $unitId)
            ->whereIn('id', $accessibleIds)
            ->withCount(['person as personnel_count'])
            ->with(['unitType'])
            ->get();
    }

    /**
     * Search units by name, returning matches plus their ancestor chain for expansion.
     *
     * @param  array<int>  $accessibleIds
     * @return array{matches: Collection<int, Unit>, ancestorsToExpand: array<int>}
     */
    public function search(string $term, array $accessibleIds): array
    {
        // mb_strlen: a Persian/Arabic term is multi-byte, so strlen() would let
        // a 1-character Persian term through the 2-character gate.
        if (mb_strlen($term) <= 2) {
            return ['matches' => collect(), 'ancestorsToExpand' => []];
        }

        $matchingUnits = Unit::where('name', 'LIKE', "%{$term}%")
            ->whereIn('id', $accessibleIds)
            ->with(['parent'])
            ->get();

        $ancestorsToExpand = [];

        foreach ($matchingUnits as $unit) {
            $ancestorsToExpand[] = $unit->id;
            $ancestorsToExpand = array_merge(
                $ancestorsToExpand,
                $this->ancestorChain($unit)
            );
        }

        return [
            'matches' => $matchingUnits,
            'ancestorsToExpand' => array_unique($ancestorsToExpand),
        ];
    }

    /**
     * Walk the ancestor chain (unit → parent → grandparent → … → root)
     * and return all ancestor IDs for expansion.
     *
     * @return array<int>
     */
    private function ancestorChain(Unit $unit): array
    {
        $ids = [];
        $visited = [(int) $unit->id => true];
        $current = $unit;

        while ($current && $current->parent_id) {
            $parent = $current->parent;
            if (! $parent || isset($visited[(int) $parent->id])) {
                break;
            }
            $ids[] = (int) $parent->id;
            $visited[(int) $parent->id] = true;
            $current = $parent;
        }

        return $ids;
    }
}
