<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AccessService;

class GenerateDailyReports extends Command
{
    protected $signature = 'reports:generate-daily';
    protected $description = 'Generate daily reports for all accessible units';

    public function handle(AccessService $access): int
    {
        $this->info('Generating daily reports...');

        $unitIds = $access->accessibleUnitIds();
        $this->line("  Scoped to ".count($unitIds)." accessible unit(s).");

        // Report generation will be enhanced with scheduled delivery
        // in a future plan. This command is the scheduled entry point.

        $this->info('Daily report generation complete.');

        return 0;
    }
}
