<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ActivityLogArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ArchiveOldRecords extends Command
{
    protected $signature = 'data:archive {--months=12 : رکوردهای قدیمی‌تر از این تعداد ماه آرشیو شوند}
                                        {--dry-run : بدون انتقال، فقط اعتبارسنجی مسیر نوشتن}';

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
            $this->warn('  Dry run — validating the write path, then rolling back.');

            // A dry run that only counts validates nothing about the write it is
            // meant to protect: the json columns stayed broken for a year behind
            // a --dry-run that returned before the insert. Exercise the real
            // insert inside a transaction and throw it away (#870).
            $this->validateWritePath($cutoff);

            $this->info('Dry run complete — write path validated, nothing was moved.');

            return 0;
        }

        if ($count === 0) {
            $this->info('  Nothing to archive.');
            $this->info('Data archival complete.');

            return 0;
        }

        $moved = 0;

        // #870: insert-then-delete with no transaction. When the insert failed,
        // rows already written were left in the archive while the delete never
        // ran — a half-finished archive that the next run then double-counts.
        // One transaction per chunk makes the pair atomic.
        $query->orderBy('id')->chunkById(1000, function ($logs) use (&$moved) {
            DB::transaction(function () use ($logs, &$moved) {
                /** @var ActivityLog $log */
                foreach ($logs as $log) {
                    // Write through the model rather than ActivityLogArchive::insert().
                    // The bulk insert passed the `array` casts straight to PDO —
                    // Connection::prepareBindings() special-cases only
                    // DateTimeInterface and bool, so the array reached Postgres
                    // untouched and the whole chunk died with 22P02 /
                    // "Array to string conversion". Letting the model's `array`
                    // cast do the encoding fixes both json columns and any
                    // future one in the same move.
                    (new ActivityLogArchive)->forceFill($this->mapArchiveRow($log))->save();
                }

                $ids = $logs->pluck('id')->all();
                ActivityLog::whereIn('id', $ids)->delete();

                $moved += count($ids);
            });
        });

        $this->line("  Archived {$moved} record(s) to activity_log_archives.");
        $this->info('Data archival complete.');

        return 0;
    }

    /**
     * One source row -> one archive row.
     *
     * `old_values` / `new_values` are passed through UNENCODED on purpose:
     * ActivityLogArchive casts both to `array`, and an `array` cast encodes on
     * the way in (and decodes on the way out). A null stays SQL NULL instead of
     * becoming the four-character string "null", which a bare
     * `json_encode($x) ?? null` would produce.
     *
     * @return array<string, mixed>
     */
    private function mapArchiveRow(ActivityLog $log): array
    {
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
    }

    /**
     * Run the real insert inside a transaction and throw it away.
     *
     * Only the FIRST chunk is validated, so a full run stays cheap — the point
     * is to prove the write path works before the operator commits to it, not to
     * move every row twice. A failure surfaces here instead of on Monday 04:00.
     */
    private function validateWritePath(mixed $cutoff): void
    {
        $firstChunk = ActivityLog::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(1000)
            ->get();

        if ($firstChunk->isEmpty()) {
            $this->line('  Nothing to validate.');

            return;
        }

        try {
            DB::transaction(function () use ($firstChunk) {
                /** @var ActivityLog $log */
                foreach ($firstChunk as $log) {
                    (new ActivityLogArchive)->forceFill($this->mapArchiveRow($log))->save();
                }

                // Deliberate: rolls the validation back.
                throw new RuntimeException('data:archive dry-run rollback');
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'data:archive dry-run rollback') {
                throw $e;
            }

            $this->line('  Write path OK — '.$firstChunk->count().' record(s) validated and rolled back.');
        }
    }
}
