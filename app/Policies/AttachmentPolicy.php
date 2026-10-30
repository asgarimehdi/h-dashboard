<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use App\Services\AccessService;

/**
 * Issue #1276 — an attachment is exactly as visible as the ticket that owns
 * it, and nothing else. This mirrors `TicketCommentPolicy::view`; the comment
 * policy cannot be called directly because its signature types `TicketComment`.
 */
class AttachmentPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Attachment $attachment): bool
    {
        $unitId = $attachment->ticket?->unit_id;

        if ($unitId === null) {
            return false;
        }

        // Deliberately loose, same as `TicketCommentPolicy::view`: the ids may
        // come back as strings from a cache. An empty list is "in scope of
        // nothing" and falls through to `false` — never treat it as open.
        return in_array($unitId, app(AccessService::class)->accessibleUnitIds($user));
    }
}
