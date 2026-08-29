<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ActivityLogArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveOldRecords extends Command
{
    protected $signature = 'data:archive {--months=12 : رکوردهای قدیمی‌تر از این تعداد ماه آرشیو شوند}
                                        {--dry-run : فقط شمارش کند، بدون انتقال}';

    protected $description = 'Archive old activity log records to historical storage';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $cutoff = now()->subMonths($months);
        $dryRun = (bool) $this->option('dry-run');

        $query = ActivityLog::query()->where('created_at', '<', $cutoff);
        $count = $query->count();

        $this->info("Archiving activity logs older than {$months} months (before {$cutoff->toDateTimeString()})...");
        $this->line("  Found {$count} record(s) eligible for archival.");

        if ($dryRun) {
            $this->warn('  Dry run — no records were moved.');
            $this->info('Dry run complete.');

            return 0;
        }

        if ($count === 0) {
            $this->info('  Nothing to archive.');
            $this->info('Data archival complete.');

            return 0;
        }

        $moved = 0;
        $query->orderBy('id')->chunkById(1000, function ($logs) use (&$moved) {
            $rows = $logs->map(function (ActivityLog $log) {
                return [
                    'user_id' => $log->user_id,
                    'type' => $log->type,
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'description' => $log->description,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'original_created_at' => $log->created_at,
                    'original_updated_at' => $log->updated_at,
                    'archived_at' => now(),
                ];
            })->all();

            ActivityLogArchive::insert($rows);
            $ids = $logs->pluck('id')->all();
            ActivityLog::whereIn('id', $ids)->delete();
            $moved += count($ids);
        });

        $this->line("  Archived {$moved} record(s) to activity_log_archives.");
        $this->info('Data archival complete.');

        return 0;
    }
}
