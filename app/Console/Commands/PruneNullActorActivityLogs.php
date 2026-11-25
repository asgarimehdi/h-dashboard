<?php

namespace App\Console\Commands;

use App\Jobs\ArchiveActivityLogsJob;
use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;

/**
 * Issue #1809: null-actor activity rows are written by design — the queue,
 * scheduler and console paths have no authenticated user
 * ({@see ActivityLogService::actorId()}), `logAs(null, ...)` is used on
 * restore, and the `users` FK is `ON DELETE SET NULL`. SQL `whereIn('user_id',
 * …)` can never match NULL, so {@see ArchiveActivityLogsJob}'s
 * 90-day window silently skips every one of them; they used to survive until
 * the 12-month `data:archive`.
 *
 * This sweep deletes ONLY null-actor rows. Scheduling the archive job
 * org-wide instead would also delete user-owned logs older than 90 days and
 * bypass the 12-month archive policy, which is why this is a separate
 * command rather than a flag on that job. `data:archive` and its 12-month
 * policy are untouched; user-owned rows are never candidates here.
 */
class PruneNullActorActivityLogs extends Command
{
    protected $signature = 'activity:prune-null-actor
                            {--days=90 : فقط لاگ‌های سیستمی قدیمی‌تر از این تعداد روز حذف می‌شوند}
                            {--dry-run : فقط گزارش می‌دهد، چیزی حذف نمی‌شود}';

    protected $description = 'Delete null-actor (system) activity logs older than the retention window';

    /**
     * Rows per delete chunk. Mirrors the archive job and `data:archive`.
     */
    private const CHUNK = 1000;

    public function handle(): int
    {
        // 30 mirrors the Tools page's activityDays validation (#950); the
        // scheduled run passes no options and uses the 90-day default.
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 30]]);

        if ($days === false) {
            $this->error('--days باید عدد صحیح و دست‌کم ۳۰ باشد.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // whereNull is hard-coded on purpose: there is no flag that can widen
        // this sweep to user-owned rows.
        $query = ActivityLog::query()
            ->whereNull('user_id')
            ->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d لاگ سیستمی قدیمی‌تر از %d روز قابل حذف است.', $query->count(), $days));

            return self::SUCCESS;
        }

        // Each chunk is an independent, idempotent delete: a crash mid-run
        // leaves the rest to the next run instead of needing a transaction.
        $deleted = 0;
        $query->orderBy('id')->chunkById(self::CHUNK, function ($logs) use (&$deleted) {
            ActivityLog::whereIn('id', $logs->pluck('id'))->delete();
            $deleted += $logs->count();
        });

        $this->info(sprintf('%d لاگ سیستمی قدیمی‌تر از %d روز حذف شد.', $deleted, $days));

        // An empty sweep is not an event worth auditing, matching
        // PruneBackupArchives and PruneImportSheets.
        if ($deleted > 0) {
            ActivityLogService::logAs(null, 'system', null, sprintf(
                '%d لاگ سیستمی قدیمی‌تر از %d روز حذف شد.', $deleted, $days
            ));
        }

        return self::SUCCESS;
    }
}
