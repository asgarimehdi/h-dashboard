<?php

namespace App\Ai\Tools;

use App\Models\Ticket;

class GetTicketsTool extends Tool
{
    public function name(): string
    {
        return 'get_tickets';
    }

    public function description(): string
    {
        return 'Get recent support tickets. Filter by status (created, forwarded, accepted, completed, rejected). Shows subject, priority, unit, and creation date.';
    }

    public function parameters(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'description' => 'Filter by status: created, forwarded, accepted, completed, rejected',
                'required' => false,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of results (default 10, max 50)',
                'required' => false,
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $query = Ticket::query()
            ->with(['unit', 'user', 'assignee'])
            ->orderByDesc('created_at');

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $limit = min((int) ($arguments['limit'] ?? 10), 50);

        return $query->limit($limit)
            ->get()
            ->map(fn ($t) => [
                'ticket_code' => $t->ticket_code,
                'subject' => $t->subject,
                'status' => $t->status,
                'priority' => $t->priority,
                'unit' => $t->unit?->name,
                'creator' => $t->user?->name ?? $t->user?->email,
                'assignee' => $t->assignee?->name ?? $t->assignee?->email,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);
    }
}
