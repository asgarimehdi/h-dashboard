<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticket;

class GenerateDueMaintenance extends Command
{
    protected $signature = 'maintenance:generate-due';
    protected $description = 'Generate due maintenance tasks from preventive maintenance schedules';

    public function handle(): int
    {
        $this->info('Generating due maintenance tasks...');

        // Placeholder: preventive maintenance scheduling will be defined
        // in Plan 007. This command is the scheduled entry point.
        $pendingCount = Ticket::where('status', '!=', 'completed')->count();

        $this->line("  Current open tickets: {$pendingCount}");

        $this->info('Maintenance task generation complete.');

        return 0;
    }
}
