<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $fillable = ['name'];

    // رابطه یک به چند با شهرستان‌ها
    public function counties()
    {
        return $this->hasMany(County::class);
    }

    // رابطه یک به چند با واحدهای سازمانی سطح استان
    public function Units()
    {
        // واحدهایی که در سطح استان هستند (county_id null)
        return $this->hasMany(Unit::class, 'province_id');
    }
    public function boundary()
    {
        return $this->belongsTo(Boundary::class);
    }
}
