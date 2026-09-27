<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $action
 * @property string|null $description
 * @property int|null $to_unit_id
 * @property int|null $to_user_id
 * @property bool $is_internal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, Attachment> $attachments
 *
 * @method static Builder<static> where(string $column, mixed $value)
 */
class TaskActivity extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'action',
        'description',
        'is_internal',
        'to_unit_id',
        'to_user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'activity_id');
    }
}
