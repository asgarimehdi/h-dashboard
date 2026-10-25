<?php

namespace App\Models;

use Database\Factories\DiseaseCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $disease_id
 * @property int $unit_id
 * @property int $subject_unit_id
 * @property Carbon $diagnosed_at
 * @property Carbon $recorded_at
 * @property array<string, mixed> $fields
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read Disease $disease
 * @property-read Unit $unit
 * @property-read Unit $subjectUnit
 *
 * @method static Builder<static> visibleTo(array<int> $accessibleUnitIds)
 */
class DiseaseCase extends Model
{
    /** @use HasFactory<DiseaseCaseFactory> */
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'disease_id',
        'unit_id',
        'subject_unit_id',
        'diagnosed_at',
        'recorded_at',
        'fields',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'fields' => 'array',
        'diagnosed_at' => 'date',
        'recorded_at' => 'date',
    ];

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Disease, $this> */
    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }

    /**
     * The unit that recorded the case (session-resolved, later phase).
     */
    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The patient's unit (decision 8) — not the recording unit.
     */
    /** @return BelongsTo<Unit, $this> */
    public function subjectUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'subject_unit_id');
    }

    /**
     * Cases visible in the actor's unit scope (V1–V5).
     *
     * A case is visible when either the recording unit or the patient's unit
     * is in scope. The `orWhereIn` stays inside one group so a later
     * `where('disease_id', …)` / date filter is still applied to every row —
     * and an empty scope compiles to `0 = 1` (fail-closed).
     *
     * @param  Builder<DiseaseCase>  $query
     * @param  array<int>  $accessibleUnitIds
     * @return Builder<DiseaseCase>
     */
    public function scopeVisibleTo(Builder $query, array $accessibleUnitIds): Builder
    {
        return self::applyVisibility($query, $accessibleUnitIds);
    }

    /**
     * The single visibility predicate shared by cases and patients.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int>  $accessibleUnitIds
     * @return Builder<TModel>
     */
    public static function applyVisibility(Builder $query, array $accessibleUnitIds): Builder
    {
        return $query->where(function (Builder $q) use ($accessibleUnitIds): void {
            $q->whereIn('unit_id', $accessibleUnitIds)
                ->orWhereIn('subject_unit_id', $accessibleUnitIds);
        });
    }
}
