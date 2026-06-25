<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TodoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'is_completed' => $this->is_completed,
            'unit_id' => $this->unit_id,
            'creator' => [
                'id' => $this->creator->id,
                'name' => $this->creator->person?->f_name.' '.$this->creator->person?->l_name,
            ],
            'users' => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->person?->f_name.' '.$user->person?->l_name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
