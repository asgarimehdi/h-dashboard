<?php

namespace App\Exceptions;

use RuntimeException;

class TicketAlreadyAcceptedException extends RuntimeException
{
    public function __construct(int $ticketId)
    {
        parent::__construct("Ticket #{$ticketId} was already accepted by another user.");
    }
}
