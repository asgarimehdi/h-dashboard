<?php

namespace App\Http\Resources;

use App\Models\Todo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int|null $id
 * @property-read string $title
 * @property-read Carbon|null $start_at
 * @property-read Carbon|null $end_at
 * @property-read bool $is_completed
 * @property-read int|null $unit_id
 * @property-read int|null $user_id
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $updated_at
 */
class TodoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Todo $model */
        $model = $this->resource;

        return [
            'id' => $model->id,
            'title' => $model->title,
            'start_at' => $model->start_at,
            'end_at' => $model->end_at,
            'is_completed' => $model->is_completed,
            'unit_id' => $model->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $model->unit->id,
                'name' => $model->unit->name,
            ]),
            'user_id' => $model->user_id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];
    }
}
