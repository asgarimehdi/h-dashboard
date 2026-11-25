<?php

namespace App\Models;

use App\Support\AttachmentFiles;
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

    /**
     * Issue #1421 — a directly deleted attachment row takes its file with it.
     *
     * This is the model-level guard only: ticket deletes are handled in
     * `Ticket::booted()` (its FK cascade fires after model events, so this
     * hook can never run there), and a future activity-delete path needs its
     * own collector in `TaskActivity`.
     *
     * The `local` disk sets `throw => false` (#1276), so a row whose file is
     * already gone still deletes cleanly.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment): void {
            // The traversal guard (`..`) and the legacy-prefix normalisation
            // live in the shared helper, so the restore path and this hook
            // cannot drift apart (#1776).
            AttachmentFiles::delete([$attachment->file_path]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
