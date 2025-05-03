<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    protected $fillable = ['name', 'type', 'parent_id', 'boundary_id'];

    // رابطه با والد (برای شهرستان‌ها، استان والد است)
    public function parent()
    {
        return $this->belongsTo(Region::class, 'parent_id');
    }

    // رابطه با فرزندان (برای استان‌ها، شهرستان‌ها فرزندان هستند)
    public function children()
    {
        return $this->hasMany(Region::class, 'parent_id');
    }

    // رابطه با واحدهای سازمانی
    public function units()
    {
        return $this->hasMany(Unit::class, 'region_id');
    }

    // رابطه با مرزها
    public function boundary()
    {
        return $this->belongsTo(Boundary::class);
    }
}