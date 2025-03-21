<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitType extends Model
{
    protected $fillable = ['name', 'description'];

    /**
     * رابطه برای دریافت نوع‌های والد مجاز برای یک نوع واحد.
     * این رابطه از جدول pivot unit_type_relationships استفاده می‌کند.
     */
    public function allowedParentTypes()
    {
        return $this->belongsToMany(
            UnitType::class,
            'unit_type_relationships',
            'child_unit_type_id',
            'allowed_parent_unit_type_id'
        );
    }
}