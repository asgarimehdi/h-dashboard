<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// A sweep can run for 300s, so it goes on the connection whose `retry_after`
// (360s) outlives this job's $timeout. The default `redis` connection reserves a
// job for only 90s and a second worker would re-reserve a sweep that is still
// deleting rows (#1010).
#[Connection('redis-maintenance')]
class ArchiveActivityLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public int $days;

    /** @var array<int> */
    public array $unitIds = [];

    /**
     * @param  int  $days  حذف لاگ‌های قدیمی‌تر از این تعداد روز
     * @param  array<int>  $unitIds  آی‌دی واحدها؛ خالی یعنی سراسری (همهٔ کاربران زنده)
     */
    public function __construct(int $days = 90, array $unitIds = [])
    {
        $this->days = $days;
        $this->unitIds = $unitIds;
    }

    public function handle(): int
    {
        // Org-wide retention (empty $unitIds) is every live user, including
        // accounts whose person has no usable unit — and, if such a row could
        // ever exist, accounts with no person row at all. Never resolve
        // accessibleUnitIds() here: it is request-scoped and always [] on a
        // worker, so the job would silently delete nothing (#836). A caller that
        // wants a scoped run passes $unitIds (the Tools page does, #950).
        $userIds = $this->unitIds === []
            ? User::query()->pluck('id')->toArray()
            : User::whereHas('person', fn ($q) => $q->whereIn('u_id', $this->unitIds))
                ->pluck('id')
                ->toArray();

        if (empty($userIds)) {
            Log::info('ArchiveActivityLogsJob: no users in scope, nothing archived');

            return 0;
        }

        $cutoff = now()->subDays($this->days);
        $deleted = 0;

        ActivityLog::whereIn('user_id', $userIds)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(1000, function ($logs) use (&$deleted) {
                ActivityLog::whereIn('id', $logs->pluck('id'))->delete();
                $deleted += $logs->count();
            });

        Log::info("ArchiveActivityLogsJob: deleted {$deleted} activity logs older than {$this->days} days");

        return $deleted;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ArchiveActivityLogsJob failed: '.$exception->getMessage());
    }
}
