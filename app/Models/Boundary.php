<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Boundary extends Model
{
    protected $table = 'boundaries';

    protected $guarded = [];
    protected $casts = [
        'multipolygon' => 'multipolygon',
    ];

    /**
     * ارتباط با جدول Province
     */
    public function province(): HasOne
    {
        return $this->hasOne(Province::class);
    }

    /**
     * ارتباط با جدول County
     */
    public function county(): HasOne
    {
        return $this->hasOne(County::class);
    }

    /**
     * ارتباط با جدول unit
     */
    public function unit(): HasOne
    {
        return $this->hasOne(Unit::class);
    }
    protected $appends = ['geojson'];

    public function getGeojsonAttribute()
    {
        return \DB::table('boundaries')
            ->where('id', $this->id)
            ->selectRaw('ST_AsGeoJSON(boundary) as geojson')
            ->value('geojson');
    }

}
