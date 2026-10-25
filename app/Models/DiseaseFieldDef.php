<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $disease_id
 * @property string $field_key
 * @property string $label_fa
 * @property string $type
 * @property string|null $unit
 * @property array<int, string>|null $options
 * @property bool $required
 * @property int $sort
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Disease $disease
 */
class DiseaseFieldDef extends Model
{
    protected $fillable = [
        'disease_id',
        'field_key',
        'label_fa',
        'type',
        'unit',
        'options',
        'required',
        'sort',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Disease, $this> */
    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }
}
