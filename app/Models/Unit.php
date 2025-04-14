<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Unit extends Model
{
    public function person(): hasMany
    {
        return $this->hasMany(Person::class);
    }
    protected $fillable = [
        'name',
        'description',
        'province_id',
        'county_id',
        'parent_id',
        'unit_type_id',
        'boundary_id',

    ];
    public function unitType()
    {
        return $this->belongsTo(UnitType::class);
    }

    // اگر این واحد در سطح استان باشد
    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    // اگر این واحد مربوط به شهرستان باشد
    public function county()
    {
        return $this->belongsTo(County::class);
    }

    // رابطه برای ساختار سلسله مراتب: والد
    public function parent()
    {
        return $this->belongsTo(Unit::class, 'parent_id');
    }

    // رابطه برای ساختار سلسله مراتب: فرزندان
    public function children()
    {
        return $this->hasMany(Unit::class, 'parent_id');
    }
    public function boundary(): hasOne
    {
        return $this->hasOne(Boundary::class, 'boundary_id');
    }
}
