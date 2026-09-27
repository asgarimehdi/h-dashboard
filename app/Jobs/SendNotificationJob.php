<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public int $userId,
        public string $type,
        public string $title,
        public ?string $body = null,
        public string $icon = 'o-bell',
        public string $color = 'text-info',
        public ?string $url = null,
        public ?array $data = null,
    ) {}

    public function handle(): void
    {
        NotificationService::send(
            $this->userId,
            $this->type,
            $this->title,
            $this->body,
            $this->icon,
            $this->color,
            $this->url,
            $this->data
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendNotificationJob failed', [
            'user_id' => $this->userId,
            'type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }
}
