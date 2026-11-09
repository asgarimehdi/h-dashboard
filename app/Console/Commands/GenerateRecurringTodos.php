<?php

namespace App\Console\Commands;

use App\Models\Todo;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateRecurringTodos extends Command
{
    protected $signature = 'todos:generate-recurring {--dry-run : فقط نمایش موارد سررسید شده، بدون ایجاد}';

    protected $description = 'Generate recurring todo instances from recurrence rules';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $due = Todo::query()
            ->where('recurrence_rule', '!=', 'none')
            ->whereNotNull('recurrence_rule')
            ->where(function ($q) use ($now) {
                $q->whereNull('last_generated_at')
                    ->orWhere('last_generated_at', '<=', $now);
            })
            ->orderBy('id')
            ->get();

        $this->info('Generating recurring todos...');
        $this->line("  Found {$due->count()} recurring todo(s) due for generation.");

        if ($dryRun) {
            $wouldCreate = 0;
            $notReached = 0;
            /** @var Todo $template */
            foreach ($due as $template) {
                $next = $template->nextOccurrence();
                if ($next !== null && ! $next->greaterThan($now)) {
                    $wouldCreate++;
                } else {
                    $notReached++;
                }
            }

            $this->line("  Would create {$wouldCreate} instance(s); {$notReached} template(s) not yet reached.");
            $this->warn('  Dry run — no todos were created.');
            $this->info('Recurring todo generation complete.');

            return 0;
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        /** @var Todo $template */
        foreach ($due as $template) {
            // Issue #1278: one bad template must not abort the batch. Each
            // template runs in its own transaction; a failure rolls that
            // template back, is reported, and the run continues — and the
            // exit code says so, so cron can alert on it.
            try {
                if (DB::transaction(fn () => $this->processTemplate($template, $now))) {
                    $created++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->error("  Failed template #{$template->id} ({$template->title}): {$e->getMessage()}");
            }
        }

        $this->line("  Created {$created} new todo instance(s).");

        if ($skipped > 0) {
            $this->line("  Skipped {$skipped} template(s) — occurrence not reached or already generated.");
        }

        if ($failed > 0) {
            $this->error("  {$failed} template(s) failed — see the log.");
            $this->info('Recurring todo generation complete.');

            return 1;
        }

        $this->info('Recurring todo generation complete.');

        return 0;
    }

    /**
     * Generate the instance for one template's next occurrence (or skip it).
     * Returns true when an instance was created.
     *
     * The guard key is the occurrence itself — `last_generated_at` stores the
     * `start_at` of the newest generated instance, never a run time — and it
     * deliberately contains no `created_at` comparison: such a guard would
     * always match after the first success and kill recurrence entirely.
     */
    private function processTemplate(Todo $template, CarbonInterface $now): bool
    {
        /** @var Todo|null $locked */
        $locked = Todo::query()->whereKey($template->id)->lockForUpdate()->first();
        if ($locked === null) {
            // Deleted between reading the due set and its turn.
            return false;
        }

        $next = $locked->nextOccurrence();
        if ($next === null) {
            $this->warn("  Skipping template #{$locked->id} — no next occurrence (rule not recurring).");

            return false;
        }

        if ($next->greaterThan($now)) {
            // Occurrence gate: only a reached occurrence is generated, which
            // also makes a same-day rerun a natural no-op (Contract A).
            return false;
        }

        if ($this->occurrenceExists($locked, $next)) {
            // last_generated_at = start_at of the newest generated instance
            // (an occurrence, not a run time) — repairing it here advances a
            // half-applied pre-fix row that would otherwise stay due forever.
            $locked->update(['last_generated_at' => $next]);
            $this->warn("  Skipping template #{$locked->id} — instance for {$next->toDateTimeString()} already exists.");

            return false;
        }

        Todo::create([
            'title' => $template->title,
            'start_at' => $next,
            'end_at' => null,
            'is_completed' => false,
            'unit_id' => $template->unit_id,
            'user_id' => $template->user_id,
            'recurrence_rule' => 'none',
        ]);

        // Same meaning as above: this is the generated occurrence, not the
        // time this command ran.
        $locked->update(['last_generated_at' => $next]);

        return true;
    }

    private function occurrenceExists(Todo $template, CarbonInterface $occurrence): bool
    {
        return Todo::query()
            ->where('title', $template->title)
            ->where('unit_id', $template->unit_id)
            ->where('recurrence_rule', 'none')
            ->where('start_at', $occurrence)
            ->exists();
    }
}
