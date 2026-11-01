<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, UnitType> $allowedParentTypes
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class UnitType extends Model
{
    protected $fillable = ['name', 'description'];

    protected static function booted(): void
    {
        // Issue #1348: a UnitType write changes payloads cached under five
        // namespaces — `by_type` in report_units, `staffingRatio` labels in
        // hr_stats, unitType eager loads in unit_hierarchy, the map payload
        // in report_no_boundary, and UnitType::all() in unit_types. Model
        // events (decision ب), mirroring Person's bumps.
        $bump = function (): void {
            $cache = app(CacheInvalidationServiceInterface::class);
            foreach (['report_units', 'hr_stats', 'unit_hierarchy', 'report_no_boundary', 'unit_types'] as $namespace) {
                $cache->increment($namespace);
            }
        };

        static::created($bump);
        static::updated($bump);
        static::deleted($bump);
    }

    /**
     * رابطه برای دریافت نوع‌های والد مجاز برای یک نوع واحد.
     * این رابطه از جدول pivot unit_type_relationships استفاده می‌کند.
     */
    public function allowedParentTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            UnitType::class,
            'unit_type_relationships',
            'child_unit_type_id',
            'allowed_parent_unit_type_id'
        );
    }
}
