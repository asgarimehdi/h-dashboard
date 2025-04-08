<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class County extends Model
{
    protected $fillable = ['name', 'province_id'];

    // شهرستان متعلق به یک استان
    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    // رابطه یک به چند با واحدهای سازمانی سطح شهرستان
    public function Units()
    {
        return $this->hasMany(Unit::class, 'county_id');
    }
    public function boundary()
    {
        return $this->belongsTo(Boundary::class);
    }
}
