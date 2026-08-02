<?php

namespace App\Policies;

use App\Models\TicketComment;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Auth\Access\Response;

class TicketCommentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Access controlled via controller with AccessService
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TicketComment $comment): bool
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);
        return in_array($comment->ticket->unit_id, $accessibleIds);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // Access controlled via controller with AccessService
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TicketComment $comment): bool
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);
        
        if (! in_array($comment->ticket->unit_id, $accessibleIds)) {
            return false;
        }

        return $comment->canBeEditedBy($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TicketComment $comment): bool
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);
        
        if (! in_array($comment->ticket->unit_id, $accessibleIds)) {
            return false;
        }

        return $comment->canBeDeletedBy($user);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TicketComment $comment): bool
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);
        
        if (! in_array($comment->ticket->unit_id, $accessibleIds)) {
            return false;
        }

        return $user->can('manage_tickets') || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TicketComment $comment): bool
    {
        return $user->hasRole('admin');
    }
}
