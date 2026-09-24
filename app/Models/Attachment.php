<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $ticket_id
 * @property int|null $user_id
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $file_size
 * @property int|null $activity_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Ticket|null $ticket
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class Attachment extends Model
{
    protected $fillable = ['user_id', 'file_path', 'file_name', 'file_size', 'ticket_id', 'activity_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
