<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'parent_id',
        'body',
        'body_html',
        'is_system',
        'system_event',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    /**
     * Get the ticket that owns the comment.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the user that authored the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent comment (for threaded replies).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'parent_id');
    }

    /**
     * Get child comments (replies).
     */
    public function children(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'parent_id')->with('user.person');
    }

    /**
     * Get all descendants (recursive).
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get reactions on this comment.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(TicketCommentReaction::class, 'comment_id');
    }

    /**
     * Scope: only root comments (no parent).
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: only system comments.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope: only user comments (not system).
     */
    public function scopeUser($query)
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope: with reaction counts.
     */
    public function scopeWithReactionCounts($query)
    {
        return $query->withCount(['reactions as reaction_counts' => function ($q) {
            $q->selectRaw('reaction, count(*)')
              ->groupBy('reaction');
        }]);
    }

    /**
     * Check if comment can be edited (author only, within 15 minutes).
     */
    public function canBeEditedBy(User $user): bool
    {
        if ($this->user_id !== $user->id) {
            return false;
        }
        return $this->created_at->diffInMinutes(now()) <= 15;
    }

    /**
     * Check if comment can be deleted (author or admin).
     */
    public function canBeDeletedBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }
        return $user->can('manage_unit_tickets') || $user->hasRole('admin');
    }
}
