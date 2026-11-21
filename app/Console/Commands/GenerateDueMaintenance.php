<?php

namespace App\Console\Commands;

use App\Models\MaintenanceSchedule;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateDueMaintenance extends Command
{
    protected $signature = 'maintenance:generate-due {--dry-run : فقط نمایش موارد سررسید شده، بدون ایجاد تیکت}';

    protected $description = 'Generate due maintenance tickets from preventive maintenance schedules';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $dueWhere = function ($q) {
            $q->whereNull('next_due_at')
                ->orWhere('next_due_at', '<=', now());
        };

        $totalDue = MaintenanceSchedule::query()->where($dueWhere)->count();

        // Issue #876 (guard): a schedule without a unit can never produce a
        // ticket — `tickets.unit_id` is NOT NULL — and letting one reach
        // Ticket::create() used to abort the whole nightly run with a 23502
        // and starve every schedule after it. Unit-less rows come from
        // org-wide schedules and from the unit-delete FK (set null); until
        // the product rule for them is decided they are skipped, and the
        // skip is counted out loud instead of dropped silently.
        $skippedNoUnit = MaintenanceSchedule::query()->where($dueWhere)->whereNull('unit_id')->count();
        $schedules = MaintenanceSchedule::query()->where($dueWhere)->whereNotNull('unit_id')->get();

        $this->info('Generating due maintenance tasks...');
        $this->line("  Found {$totalDue} maintenance schedule(s) due.");

        if ($skippedNoUnit > 0) {
            $this->warn("  Skipping {$skippedNoUnit} schedule(s) without a unit — a maintenance ticket requires a unit.");
        }

        if ($dryRun) {
            $this->warn('  Dry run — no tickets were created.');
            $this->info('Maintenance task generation complete.');

            return 0;
        }

        $created = 0;
        $failed = 0;
        /** @var MaintenanceSchedule $schedule */
        foreach ($schedules as $schedule) {
            // Issue #876 (isolation): one bad schedule must not abort the
            // batch. Each schedule runs in its own transaction; a failure
            // rolls that schedule back, is reported, and the run continues —
            // and the exit code says so, so cron can alert on it.
            try {
                $made = DB::transaction(fn () => $this->processSchedule($schedule));
                if ($made) {
                    $created++;
                }
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->error("  Failed schedule #{$schedule->id} ({$schedule->title}): {$e->getMessage()}");
            }
        }

        $this->line("  Created {$created} maintenance ticket(s).");

        if ($failed > 0) {
            $this->error("  {$failed} schedule(s) failed — see the log.");
            $this->info('Maintenance task generation complete.');

            return 1;
        }

        $this->info('Maintenance task generation complete.');

        return 0;
    }

    /**
     * Create the ticket for one due schedule (or advance it past an
     * already-open ticket). Returns true when a ticket was created.
     */
    private function processSchedule(MaintenanceSchedule $schedule): bool
    {
        // Issue #1610: the guard below and the insert are two statements, so
        // two overlapping runs — a manual run racing the scheduled one, or a
        // run that outlived the scheduler's `withoutOverlapping` window — both
        // read "no open ticket" and both write one. The row lock is what makes
        // the pair atomic: the second run waits here, then its guard sees the
        // ticket the first run committed. Same pattern as the sibling writer
        // GenerateRecurringTodos (#1278). This is inside the per-schedule
        // transaction that handle() opens, so the lock lives until commit.
        /** @var MaintenanceSchedule|null $locked */
        $locked = MaintenanceSchedule::query()->whereKey($schedule->id)->lockForUpdate()->first();

        if ($locked === null) {
            // Deleted between reading the due set and its turn.
            return false;
        }

        // Issue #533: idempotency guard — skip if an open ticket already exists
        // for this schedule (prevents duplicates on scheduler overlap or crash).
        // Issue #876: `unit_id` is guaranteed non-null here — unit-less
        // schedules are filtered out in handle() — so this guard can actually
        // match. (With a null unit it compiled to `IS NULL`, which can never
        // match the NOT NULL `tickets.unit_id` column and was vacuous.)
        $existingTicket = Ticket::where('unit_id', $locked->unit_id)
            ->where('subject', $locked->title)
            ->where('status', '!=', 'completed')
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($existingTicket) {
            $this->warn("  Skipping schedule #{$locked->id} — open ticket already exists.");
            // Still advance the schedule to avoid getting stuck
            $this->advance($locked);

            return false;
        }

        Ticket::create([
            'ticket_code' => 'T-'.strtoupper(Str::random(8)),
            'subject' => $locked->title,
            'content' => 'Generated from maintenance schedule #'.$locked->id,
            'status' => 'created',
            // #954: the app vocabulary is Ticket::PRIORITIES (three
            // values). `medium` passed the DB CHECK but no report reads
            // it — this unattended nightly writer must stay inside the
            // buckets every aggregation has.
            'priority' => 'normal',
            'unit_id' => $locked->unit_id,
        ]);

        $this->advance($locked);

        return true;
    }

    private function advance(MaintenanceSchedule $schedule): void
    {
        $interval = max(1, (int) $schedule->recurrence_interval);
        $next = match ($schedule->frequency) {
            'daily' => now()->addDays($interval),
            'weekly' => now()->addWeeks($interval),
            // 'monthly' — and the legacy default, which has always stepped by
            // months — use the anchored, non-overflowing step below.
            default => $this->nextMonthlyDue($schedule, $interval),
        };

        $schedule->update([
            'last_generated_at' => now(),
            'next_due_at' => $next,
        ]);
    }

    /**
     * گام ماهانهٔ بدون سرریز، لنگرشده روی روزِ ساختِ زمان‌بندی (ایشو #1623).
     *
     * `MaintenanceSchedule` ستون `start_at` ندارد؛ تنها روزِ پایدارِ ساخت
     * `created_at->day` است (تصمیم مالک). مبنا سررسیدِ فعلی است نه `now()`،
     * تا اجرای دیرهنگام روزِ اجرا را روی سررسید بعدی تحمیل نکند. حلقهٔ
     * catch-up چند ماهِ ازدست‌رفته را جبران می‌کند تا `next_due_at` در گذشته
     * نماند و زمان‌بندی برای همیشه سررسید نماند.
     */
    private function nextMonthlyDue(MaintenanceSchedule $schedule, int $interval): Carbon
    {
        $anchorDay = $schedule->created_at === null ? now()->day : $schedule->created_at->day;

        $next = ($schedule->next_due_at ?? now())->copy()->addMonthsNoOverflow($interval);
        $next->setDate($next->year, $next->month, min($anchorDay, $next->daysInMonth));

        $guard = 0;
        while ($next->lessThanOrEqualTo(now()) && $guard++ < 120) {
            $next = $next->copy()->addMonthsNoOverflow($interval);
            $next->setDate($next->year, $next->month, min($anchorDay, $next->daysInMonth));
        }

        return $next;
    }
}
