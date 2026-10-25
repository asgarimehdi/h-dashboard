<?php

namespace App\Models;

use Database\Factories\DiseaseCaseFactory;
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

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }

    /**
     * The unit that recorded the case (session-resolved, later phase).
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The patient's unit (decision 8) — not the recording unit.
     */
    public function subjectUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'subject_unit_id');
    }
}
