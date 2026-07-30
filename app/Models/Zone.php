<?php

namespace App\Models;

use App\Services\AccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Zone extends Model
{
    protected $fillable = [
        'name',
        'description',
        'color',
    ];

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'zone_units')
            ->withTimestamps();
    }

    /**
     * Scope: only include zones that have at least one unit within the user's
     * accessible organizational scope.
     */
    public function scopeAccessible(Builder $query, ?User $user = null): Builder
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        if (empty($accessibleIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('units', fn (Builder $q) => $q->whereIn('units.id', $accessibleIds));
    }
}