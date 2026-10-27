<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Builder;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DiseaseCase> $diseaseCases
 *
 * @method static Builder<static> visibleTo(array<int> $accessibleUnitIds)
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
    ];

    /** @var array<string, string> */
    protected $casts = [
        'birth_date' => 'date',
    ];

    /** @return HasMany<DiseaseCase, $this> */
    public function diseaseCases(): HasMany
    {
        return $this->hasMany(DiseaseCase::class);
    }

    /** Display name — the identity fields are exactly first + last (#1243). */
    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** Persian label for the stored English enum (`male`/`female`). */
    public function genderLabel(): string
    {
        return match ($this->gender) {
            'male' => 'مرد',
            'female' => 'زن',
            default => $this->gender,
        };
    }

    /**
     * Patients reachable through a visible case.
     *
     * Direct patient access is gated by permission (spec §4.1); this predicate
     * only applies when a patient is read through the case path.
     *
     * @param  Builder<Patient>  $query
     * @param  array<int>  $accessibleUnitIds
     * @return Builder<Patient>
     */
    public function scopeVisibleTo(Builder $query, array $accessibleUnitIds): Builder
    {
        return $query->whereHas('diseaseCases', function (Builder $q) use ($accessibleUnitIds): void {
            DiseaseCase::applyVisibility($q, $accessibleUnitIds);
        });
    }
}
