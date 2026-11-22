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

    /**
     * Is `$parentUnitTypeId` an allowed parent type for `$childUnitTypeId`?
     *
     * Issue #1649: the single definition of the allowed-parent-type rule. The
     * web form (`units.index`) and `Api\UnitController` both call this, so the
     * two write surfaces can never disagree about what a valid hierarchy is.
     *
     * A `null` parent type (a legacy unit whose `unit_type_id` is null) and a
     * type with no relationship rows both answer `false`: the rule is
     * fail-closed, exactly like the web check it replaces.
     */
    public static function allowsParentType(int $childUnitTypeId, ?int $parentUnitTypeId): bool
    {
        if ($parentUnitTypeId === null) {
            return false;
        }

        return static::query()
            ->where('child_unit_type_id', $childUnitTypeId)
            ->where('allowed_parent_unit_type_id', $parentUnitTypeId)
            ->exists();
    }
}
