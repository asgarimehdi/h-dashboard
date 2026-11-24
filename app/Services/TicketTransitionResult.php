<?php

namespace App\Services;

use App\Models\Ticket;

/**
 * #1699 (phase 1): the outcome of one `TicketTransitionService` call.
 *
 * The failure set — not a single reason — is deliberate. Each adapter keeps
 * its historical message order (D1), so the service answers "which predicates
 * failed" and the adapter answers "which one the user hears about".
 */
final readonly class TicketTransitionResult
{
    /**
     * @param  list<TicketTransitionFailure>  $failures
     */
    private function __construct(
        public bool $ok,
        public ?Ticket $ticket,
        public array $failures = [],
        public bool $todoCompleted = false,
    ) {}

    public static function success(Ticket $ticket, bool $todoCompleted = false): self
    {
        return new self(true, $ticket, [], $todoCompleted);
    }

    /**
     * @param  list<TicketTransitionFailure>  $failures
     */
    public static function failed(array $failures): self
    {
        return new self(false, null, $failures);
    }

    public function has(TicketTransitionFailure ...$failures): bool
    {
        foreach ($failures as $failure) {
            if (in_array($failure, $this->failures, true)) {
                return true;
            }
        }

        return false;
    }
}
