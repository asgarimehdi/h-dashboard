<?php

namespace App\Support;

/**
 * Ancestor-chain walk over a flat unit index (issue #1302).
 *
 * One operation only: follow `parent_id` links upward. No database, no models,
 * and no downward/descendant traversal — the org chart, `orderDepthFirst`/
 * `walk` and `collectAllIds` stay where they are.
 *
 * Callers pass the index they already have — the shape
 * `UnitsExportController::hierarchyMap()` and `PersonsExport::unitsById()`
 * build (`id => [name, parent_id]`) — plus their own scope:
 * `null` = explicitly unlimited, `[]` = in scope of nothing, an array = the
 * parent must be a member and is then named.
 *
 * A cycle, a self-parent, a parent missing from the index, an out-of-scope
 * parent or the depth cap all cut the chain silently: no throw, and no marker
 * in the output, so no consumer payload changes.
 */
final class UnitAncestry
{
    /**
     * Safety cap, not a domain rule — the deepest dev chain is six levels.
     * It exists so a corrupt linear `parent_id` chain cannot walk forever;
     * `$visited` alone already guarantees termination on a cycle.
     */
    public const MAX_DEPTH = 100;

    /**
     * Ancestors of a unit, root first, excluding the unit itself.
     *
     * @param  array<int, array{name: string, parent_id: int|null}>  $index
     * @param  array<int>|null  $accessibleIds
     * @return list<array{id: int, name: string}>
     */
    public static function chain(int $unitId, array $index, ?array $accessibleIds, int $maxDepth = self::MAX_DEPTH): array
    {
        // A unit outside the index has no known ancestry (a person whose unit
        // row is gone, an export row the map does not cover).
        if (! isset($index[$unitId])) {
            return [];
        }

        $chain = [];
        $visited = [$unitId => true];
        $parentId = $index[$unitId]['parent_id'];

        // Collected nearest-first, reversed at the end.
        for ($depth = 0; $parentId !== null && $depth < $maxDepth; $depth++) {
            $parentId = (int) $parentId;

            if (isset($visited[$parentId]) || ! isset($index[$parentId]) || ! self::inScope($parentId, $accessibleIds)) {
                break;
            }

            $visited[$parentId] = true;
            $chain[] = ['id' => $parentId, 'name' => $index[$parentId]['name']];
            $parentId = $index[$parentId]['parent_id'];
        }

        return array_reverse($chain);
    }

    /**
     * The one scope meaning every caller shares (issue #1302, decision ب):
     * `null` is explicitly unlimited, `[]` is nothing, otherwise membership.
     * Public so an adapter that must decide whether a parent is worth loading
     * reads the boundary from here instead of writing a second copy of it.
     *
     * @param  array<int>|null  $accessibleIds
     */
    public static function inScope(int $unitId, ?array $accessibleIds): bool
    {
        return $accessibleIds === null || in_array($unitId, $accessibleIds, true);
    }
}
