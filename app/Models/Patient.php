<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $kod_melli
 * @property string $first_name
 * @property string $last_name
 * @property Carbon|null $birth_date
 * @property string $gender
 * @property string|null $city
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DiseaseCase> $diseaseCases
 */
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    protected $fillable = [
        'kod_melli',
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'city',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'birth_date' => 'date',
    ];

    public function diseaseCases(): HasMany
    {
        return $this->hasMany(DiseaseCase::class);
    }
}
