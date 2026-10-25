<?php

namespace App\Models;

use Database\Factories\DiseaseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name_fa
 * @property string $slug
 * @property bool $is_active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DiseaseFieldDef> $fieldDefs
 * @property-read Collection<int, DiseaseCase> $diseaseCases
 */
class Disease extends Model
{
    /** @use HasFactory<DiseaseFactory> */
    use HasFactory;

    protected $fillable = [
        'name_fa',
        'slug',
        'is_active',
        'sort',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return HasMany<DiseaseFieldDef, $this> */
    public function fieldDefs(): HasMany
    {
        return $this->hasMany(DiseaseFieldDef::class);
    }

    /** @return HasMany<DiseaseCase, $this> */
    public function diseaseCases(): HasMany
    {
        return $this->hasMany(DiseaseCase::class);
    }
}
