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
        // Use the already loaded 'boundary' attribute if available to avoid N+1 queries
        if ($this->relationLoaded('boundary') || array_key_exists('boundary', $this->getAttributes())) {
            $boundary = $this->getAttribute('boundary');
            if ($boundary) {
                return \DB::selectOne('SELECT ST_AsGeoJSON(ST_GeomFromEWKB(decode(?, \'hex\'))) as geojson', [$boundary])->geojson ?? null;
            }

            return null;
        }

        // Fallback: query the database (this path triggers N+1 if used in a loop without eager loading)
        return \DB::table('boundaries')
            ->where('id', $this->id)
            ->selectRaw('ST_AsGeoJSON(boundary) as geojson')
            ->value('geojson');
    }
}
