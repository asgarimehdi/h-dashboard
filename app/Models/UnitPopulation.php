<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $unit_id
 * @property int $year
 * @property int $population
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 */
class UnitPopulation extends Model
{
    protected $fillable = [
        'unit_id',
        'year',
        'population',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'year' => 'integer',
        'population' => 'integer',
    ];

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
