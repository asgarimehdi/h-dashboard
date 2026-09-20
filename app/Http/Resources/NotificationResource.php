<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property-read string $id
 * @property-read string $type
 * @property-read string $title
 * @property-read string $body
 * @property-read string|null $icon
 * @property-read string|null $color
 * @property-read string|null $url
 * @property-read bool $is_read
 * @property-read Carbon|null $read_at
 * @property-read Carbon|null $created_at
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Notification $model */
        $model = $this->resource;

        return [
            'id' => $model->id,
            'type' => $model->type,
            'title' => $model->title,
            'body' => $model->body,
            'icon' => $model->icon,
            'color' => $model->color,
            'url' => $model->url,
            'data' => $model->data,
            'is_read' => $model->is_read,
            'read_at' => $model->read_at?->toISOString(),
            'created_at' => $model->created_at?->toISOString(),
        ];
    }
}
