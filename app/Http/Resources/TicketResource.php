<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $id
 * @property-read string $ticket_code
 * @property-read string $subject
 * @property-read string $content
 * @property-read string $priority
 * @property-read string $status
 * @property-read string $status_name
 * @property-read int $user_id
 * @property-read int $unit_id
 * @property-read int|null $current_assignee_id
 * @property-read string|null $deadline
 * @property-read string|null $accepted_at
 * @property-read string|null $completed_at
 * @property-read string $created_at
 * @property-read string $updated_at
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Ticket $model */
        $model = $this->resource;

        return [
            'id' => $model->id,
            'ticket_code' => $model->ticket_code,
            'subject' => $model->subject,
            'content' => $model->content,
            'priority' => $model->priority,
            'status' => $model->status,
            'status_name' => $model->status_name,
            'user_id' => $model->user_id,
            'unit_id' => $model->unit_id,
            'current_assignee_id' => $model->current_assignee_id,
            'deadline' => $model->deadline?->toISOString(),
            'accepted_at' => $model->accepted_at?->toISOString(),
            'completed_at' => $model->completed_at?->toISOString(),
            'created_at' => $model->created_at?->toISOString(),
            'updated_at' => $model->updated_at?->toISOString(),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $model->unit->id,
                'name' => $model->unit->name,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $model->user->id,
                'n_code' => $model->user->n_code,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $model->assignee->id,
                'n_code' => $model->assignee->n_code,
            ]),
            'activities' => $this->whenLoaded('activities'),
            'attachments' => $this->whenLoaded('attachments'),
        ];
    }
}
