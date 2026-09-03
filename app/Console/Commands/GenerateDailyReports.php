<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Console\Command;

class GenerateDailyReports extends Command
{
    protected $signature = 'reports:generate-daily {--unit= : فقط برای یک واحد خاص} {--dry-run : فقط نمایش، بدون ذخیره}';

    protected $description = 'Generate daily reports for all accessible units';

    public function handle(AccessService $access): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $unitFilter = $this->option('unit');

        $this->info('Generating daily reports...');

        $unitIds = $unitFilter
            ? [(int) $unitFilter]
            : $access->accessibleUnitIds();

        if (empty($unitIds)) {
            $this->warn('  No accessible units found — nothing to report.');
            $this->info('Daily report generation complete.');

            return 0;
        }

        $this->line('  Scoped to '.count($unitIds).' accessible unit(s).');

        $today = now()->toDateString();
        $created = 0;

        foreach ($unitIds as $unitId) {
            $unit = Unit::find($unitId);
            if (! $unit) {
                continue;
            }

            $openTickets = $unit->tickets()->where('status', '!=', 'completed')->count();
            $openTodos = $unit->todos()->where('is_completed', false)->count();

            $summary = "Unit {$unit->name}: {$openTickets} open tickets, {$openTodos} open todos.";

            if ($dryRun) {
                $this->line("  [dry-run] {$summary}");

                continue;
            }

            if (DailyReport::where('unit_id', $unitId)->where('report_date', $today)->exists()) {
                continue;
            }

            DailyReport::create([
                'unit_id' => $unitId,
                'report_date' => $today,
                'summary' => $summary,
                'payload' => [
                    'open_tickets' => $openTickets,
                    'open_todos' => $openTodos,
                ],
            ]);

            $created++;
        }

        $this->line("  Generated {$created} daily report(s).");
        $this->info('Daily report generation complete.');

        return 0;
    }
}
