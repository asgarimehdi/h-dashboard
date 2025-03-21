<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitTypeRelationship extends Model
{
    protected $fillable = [
        'child_unit_type_id',
        'allowed_parent_unit_type_id',
    ];
    public function childUnitType()
    {
        return $this->belongsTo(UnitType::class, 'child_unit_type_id');
    }
    public function allowedParentUnitType()
    {
        return $this->belongsTo(UnitType::class, 'allowed_parent_unit_type_id');
    }
}