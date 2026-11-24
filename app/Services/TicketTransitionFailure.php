<?php

namespace App\Services;

/**
 * #1699 (phase 1): the reasons a ticket transition can be refused.
 *
 * A transition reports the WHOLE set of violated predicates, never just the
 * first one. The two entry points (web inbox, API controller) assert the same
 * predicates in a different order and map them to different messages, so the
 * service must not decide which failure "wins" — see
 * `TicketTransitionResult::$failures`.
 */
enum TicketTransitionFailure: string
{
    /** The row was DELETEd between the caller's read and the lock. */
    case Missing = 'missing';

    /** Terminal status (`completed` / `rejected`) or an `archived_at` stamp (#929). */
    case Closed = 'closed';

    /** The locked status does not satisfy the method's status rule. */
    case StatusMismatch = 'status-mismatch';

    /** The ticket was already assigned to somebody else when it was read. */
    case AssigneeMismatch = 'assignee-mismatch';

    /** The ticket belonged to the actor when read, but not on the locked row. */
    case AssigneeRaced = 'assignee-raced';
}
