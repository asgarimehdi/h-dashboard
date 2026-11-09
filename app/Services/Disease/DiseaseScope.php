<?php

namespace App\Services\Disease;

use App\Models\Unit;

/**
 * Unit-scope helpers for disease reporting.
 *
 * Callers (Livewire/API, later phases) resolve the actor's accessible unit
 * ids; this class never touches `auth()` or `session()`, so it works inside a
 * queue worker too.
 */
final class DiseaseScope
{
    /**
     * The requested unit's subtree, restricted to what the caller may see.
     *
     * Fail-closed twice: a unit outside the accessible set yields no subtree,
     * and the CTE result is intersected with the accessible set so a caller
     * passing a partial set can never read units outside its scope.
     *
     * @param  array<int>  $accessibleUnitIds
     * @return array<int>
     */
    public function subtreeIds(int $unitId, array $accessibleUnitIds): array
    {
        $accessible = array_map(intval(...), $accessibleUnitIds);

        if (! in_array($unitId, $accessible, true)) {
            return [];
        }

        $descendants = Unit::descendantIds($unitId)
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_intersect($descendants, $accessible));
    }
}
