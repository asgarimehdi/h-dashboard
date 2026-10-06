<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
     * @param  array<int>  $unitIds  آی‌دی واحدها؛ خالی یعنی سراسری (`allUnitIds()`)
     */
    public function __construct(int $days = 90, array $unitIds = [])
    {
        $this->days = $days;
        $this->unitIds = $unitIds;
    }

    public function handle(): int
    {
        // Org-wide retention: an empty scope must NOT fall back to
        // accessibleUnitIds(), which is request-scoped and therefore always
        // `[]` on a real queue worker — the job would silently delete nothing
        // (issue #836). A caller that wants a scoped run passes $unitIds.
        $unitIds = $this->unitIds ?: app(AccessService::class)->allUnitIds();

        $userIds = User::whereHas('person', fn ($q) => $q->whereIn('u_id', $unitIds))
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
