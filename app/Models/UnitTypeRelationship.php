<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $child_unit_type_id
 * @property int|null $allowed_parent_unit_type_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read UnitType|null $childUnitType
 * @property-read UnitType|null $allowedParentUnitType
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class UnitTypeRelationship extends Model
{
    protected $fillable = [
        'child_unit_type_id',
        'allowed_parent_unit_type_id',
    ];

    public function childUnitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'child_unit_type_id');
    }

    public function allowedParentUnitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'allowed_parent_unit_type_id');
    }
}
