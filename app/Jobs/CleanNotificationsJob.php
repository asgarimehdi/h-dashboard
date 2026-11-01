<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
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
// deleting notifications (#1010).
#[Connection('redis-maintenance')]
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
     * @param  array<int>  $unitIds  آی‌دی واحدها؛ خالی یعنی سراسری (همهٔ کاربران زنده)
     */
    public function __construct(int $days = 7, array $unitIds = [])
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
            Log::info('CleanNotificationsJob: no users in scope, nothing cleaned');

            return 0;
        }

        $deleted = Notification::whereIn('user_id', $userIds)
            ->where('created_at', '<', now()->subDays($this->days))
            ->delete();

        // Issue #1348 (gate correction C3): the query-builder delete fires
        // no model event, so bump the `notifications` namespace explicitly
        // at the end of handle() — the bell cache must not wait out the
        // one-minute TTL to notice the sweep. The tools counts move too.
        $cache = app(CacheInvalidationServiceInterface::class);
        $cache->increment('notifications');
        $cache->increment('tools_stats');

        Log::info("CleanNotificationsJob: deleted {$deleted} notifications older than {$this->days} days");

        return $deleted;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CleanNotificationsJob failed: '.$exception->getMessage());
    }
}
