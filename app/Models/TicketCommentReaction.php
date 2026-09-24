<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $comment_id
 * @property int $user_id
 * @property string $reaction
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TicketComment $comment
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class TicketCommentReaction extends Model
{
    protected $fillable = [
        'comment_id',
        'user_id',
        'reaction',
    ];

    /**
     * Get the comment that owns the reaction.
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'comment_id');
    }

    /**
     * Get the user that added the reaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
