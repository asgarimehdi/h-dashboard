<?php

namespace App\Traits;

use App\Services\AccessService;
use Illuminate\Database\Eloquent\Builder;

trait HasOrganizationalScope
{
    public function scopeAccessible(Builder $query, string $unitColumn = 'unit_id'): Builder
    {
        $unitIds = app(AccessService::class)->accessibleUnitIds();

        return $query->whereIn($unitColumn, $unitIds);
    }
}
