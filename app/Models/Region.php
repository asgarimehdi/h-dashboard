<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = ['name', 'type', 'parent_id', 'boundary_id'];

    // رابطه با والد (برای شهرستان‌ها، استان والد است)
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'parent_id');
    }

    // رابطه با فرزندان (برای استان‌ها، شهرستان‌ها فرزندان هستند)
    public function children(): HasMany
    {
        return $this->hasMany(Region::class, 'parent_id');
    }

    // رابطه با واحدهای سازمانی
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'region_id');
    }

    // رابطه با مرزها
    public function boundary(): BelongsTo
    {
        return $this->belongsTo(Boundary::class);
    }
}
