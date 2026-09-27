<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $description
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $original_created_at
 * @property Carbon|null $original_updated_at
 * @property Carbon|null $archived_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class ActivityLogArchive extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'subject_type',
        'subject_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'original_created_at',
        'original_updated_at',
        'archived_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'original_created_at' => 'datetime',
        'original_updated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
