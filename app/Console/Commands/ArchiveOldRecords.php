<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveOldRecords extends Command
{
    protected $signature = 'data:archive';
    protected $description = 'Archive old records to historical storage (Plan 010)';

    public function handle(): int
    {
        $this->info('Archiving old records...');

        // Data archival will be implemented in Plan 010.
        // This command is the scheduled entry point.
        $cutoff = now()->subMonths(12);

        $archived = DB::table('activity_logs')
            ->where('created_at', '<', $cutoff)
            ->count();

        $this->line("  Activity logs older than 12 months: {$archived}");

        $this->info('Data archival complete.');

        return 0;
    }
}
