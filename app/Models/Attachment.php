<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
            $path = ltrim((string) $attachment->file_path, '/');

            // Same guard as TicketAttachmentController::show(): `file_path` is
            // database data, but a `..` segment must never address a file
            // outside the disk root.
            if ($path === '' || in_array('..', explode('/', $path), true)) {
                return;
            }

            Storage::disk('local')->delete($path);
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
