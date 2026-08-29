<?php

namespace App\Console\Commands;

use App\Models\Todo;
use Illuminate\Console\Command;

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
            ->get();

        $this->info('Generating recurring todos...');
        $this->line("  Found {$due->count()} recurring todo(s) due for generation.");

        if ($dryRun) {
            $this->warn('  Dry run — no todos were created.');
            $this->info('Recurring todo generation complete.');

            return 0;
        }

        $created = 0;
        foreach ($due as $template) {
            $next = $template->nextOccurrence() ?? $now;

            Todo::create([
                'title' => $template->title,
                'start_at' => $next,
                'end_at' => null,
                'is_completed' => false,
                'unit_id' => $template->unit_id,
                'user_id' => $template->user_id,
                'recurrence_rule' => 'none',
            ]);

            $template->update(['last_generated_at' => $next]);
            $created++;
        }

        $this->line("  Created {$created} new todo instance(s).");
        $this->info('Recurring todo generation complete.');

        return 0;
    }
}
