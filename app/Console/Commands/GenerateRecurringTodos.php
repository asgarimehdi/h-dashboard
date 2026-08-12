<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Todo;

class GenerateRecurringTodos extends Command
{
    protected $signature = 'todos:generate-recurring';
    protected $description = 'Generate recurring todos based on recurrence rules (Issue #214)';

    public function handle(): int
    {
        $this->info('Generating recurring todos...');

        // Find todos marked for recurrence that are due
        $recurringCount = Todo::where('is_completed', true)
            ->whereHas('unit')
            ->count();

        $this->line("  Found {$recurringCount} completed todos for recurrence processing.");

        // Recurrence generation will be implemented when Todo recurrence fields
        // are added (issue #214). This command is the scheduled entry point.

        $this->info('Recurring todo generation complete.');

        return 0;
    }
}
