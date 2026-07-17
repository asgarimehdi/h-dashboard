<?php

namespace App\Traits;

use App\Services\AccessService;
use Illuminate\Database\Eloquent\Builder;

trait HasOrganizationalScope
{
    public function scopeAccessible(Builder $query, string $unitColumn = 'unit_id', bool $withRelated = false): Builder
    {
        $unitIds = app(AccessService::class)->accessibleUnitIds();

        $query = $query->whereIn($unitColumn ?? 'unit_id', $unitIds);

        if ($withRelated) {
            $query->with(['unit']);
        }

        return $query;
    }
}
