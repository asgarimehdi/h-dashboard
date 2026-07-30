<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Zone extends Model
{
    protected $fillable = [
        'name',
        'description',
        'color',
    ];

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'zone_unit')
            ->withTimestamps();
    }
}