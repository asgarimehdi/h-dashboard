<?php

namespace App\Ai\Traits;

use App\Models\Hardware;
use App\Services\AccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait to apply organizational scope filtering to AI hardware tools.
 * Ensures AI tools respect the same access control as the REST API.
 */
trait AiAccessScope
{
    /**
     * Get a Hardware query scoped to the current user's accessible units.
     *
     * @return Builder<Hardware>
     */
    protected function scopedHardwareQuery(): Builder
    {
        $user = auth()->user();
        $unitIds = app(AccessService::class)->accessibleUnitIds($user);

        return Hardware::query()
            ->with(['person'])
            ->whereHas('person', fn ($q) => $q->whereIn('u_id', $unitIds));
    }
}