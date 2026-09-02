<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
