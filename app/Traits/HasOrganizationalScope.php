<?php

namespace App\Traits;

use App\Models\User;
use App\Services\AccessService;
use Illuminate\Database\Eloquent\Builder;

trait HasOrganizationalScope
{
    public function scopeAccessible(
        Builder $query,
        string $unitColumn = 'unit_id',
        bool $withRelated = false,
        ?array $unitIds = null,
        ?User $user = null,
    ): Builder {
        $unitIds ??= app(AccessService::class)->accessibleUnitIds($user);

        $query = $query->whereIn($unitColumn ?? 'unit_id', $unitIds);

        if ($withRelated) {
            $query->with(['unit']);
        }

        return $query;
    }

    /**
     * Scope for models that reach their org unit through a `person` relation
     * (person.u_id → unit) rather than a direct unit column. Behaviorally
     * identical to `whereHas('person', fn($q) => $q->whereIn('u_id', ...))`.
     */
    public function scopeAccessibleThroughPerson(Builder $query, ?array $unitIds = null, ?User $user = null): Builder
    {
        $unitIds ??= app(AccessService::class)->accessibleUnitIds($user);

        return $query->whereHas('person', fn (Builder $q) => $q->whereIn('u_id', $unitIds));
    }
}
