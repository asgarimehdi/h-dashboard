<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public int $days;

    /** @var array<int> */
    public array $unitIds = [];

    /**
     * @param  int  $days  حذف اعلان‌های قدیمی‌تر از این تعداد روز
     * @param  array<int>  $unitIds  آی‌دی واحدها (خالی = از AccessService)
     */
    public function __construct(int $days = 7, array $unitIds = [])
    {
        $this->days = $days;
        $this->unitIds = $unitIds;
    }

    public function handle(): int
    {
        $unitIds = $this->unitIds ?: app(AccessService::class)->accessibleUnitIds();

        $userIds = User::whereHas('person', fn ($q) => $q->whereIn('u_id', $unitIds))
            ->pluck('id')
            ->toArray();

        if (empty($userIds)) {
            return 0;
        }

        $deleted = Notification::whereIn('user_id', $userIds)
            ->where('created_at', '<', now()->subDays($this->days))
            ->delete();

        Log::info("CleanNotificationsJob: deleted {$deleted} notifications older than {$this->days} days");

        return $deleted;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CleanNotificationsJob failed: '.$exception->getMessage());
    }
}
