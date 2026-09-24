<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $unit_id
 * @property Carbon|null $report_date
 * @property string|null $summary
 * @property array|null $payload
 * @property int|null $generated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit|null $unit
 * @property-read User|null $generatedBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class DailyReport extends Model
{
    protected $fillable = [
        'unit_id',
        'report_date',
        'summary',
        'payload',
        'generated_by',
    ];

    protected $casts = [
        'report_date' => 'date',
        'payload' => 'array',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
