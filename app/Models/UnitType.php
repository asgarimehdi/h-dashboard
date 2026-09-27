<?php

namespace App\Models;

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
