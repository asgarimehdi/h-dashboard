<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateDailyReports extends Command
{
    protected $signature = 'reports:generate-daily {--unit= : فقط برای یک واحد خاص} {--dry-run : فقط نمایش، بدون ذخیره}';

    protected $description = 'Generate daily reports for all units (or one unit via --unit)';

    public function handle(AccessService $access): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $unitFilter = $this->option('unit');

        $this->info('Generating daily reports...');

        // Without --unit the scope is org-wide: the scheduler has no actor,
        // so accessibleUnitIds() is always `[]` there and the command would
        // exit 0 having written nothing (issue #836).
        $unitIds = $unitFilter
            ? [(int) $unitFilter]
            : $access->allUnitIds();

        if (empty($unitIds)) {
            $this->warn('  No units found — nothing to report.');
            $this->info('Daily report generation complete.');

            return 0;
        }

        $this->line('  Scoped to '.count($unitIds).' unit(s).');

        $today = now()->toDateString();
        $created = 0;

        // Issue #1021: the loop below used to cost five round-trips per unit
        // (Unit::find + one count per relation + exists + create). The units and
        // the two counts are each a single pass over the whole set now, so only
        // the deliberate check-then-act write pair stays per unit.
        //
        // Raw aggregates are equivalent here, verified rather than assumed:
        // neither Ticket nor Todo uses SoftDeletes or registers a global scope —
        // HasOrganizationalScope only offers the opt-in `accessible()` local
        // scope — so `DB::table` sees exactly the rows the old relation counts
        // did. The predicates are kept verbatim: `status != 'completed'` and
        // `is_completed = false` both exclude NULL in either form.
        $units = Unit::query()->whereIn('id', $unitIds)->get()->keyBy('id');

        $ticketCounts = DB::table('tickets')
            ->select('unit_id', DB::raw('count(*) as aggregate'))
            ->whereIn('unit_id', $unitIds)
            ->where('status', '!=', 'completed')
            ->groupBy('unit_id')
            ->pluck('aggregate', 'unit_id');

        $todoCounts = DB::table('todos')
            ->select('unit_id', DB::raw('count(*) as aggregate'))
            ->whereIn('unit_id', $unitIds)
            ->where('is_completed', false)
            ->groupBy('unit_id')
            ->pluck('aggregate', 'unit_id');

        foreach ($unitIds as $unitId) {
            $unit = $units->get($unitId);
            if (! $unit) {
                // allUnitIds() only returns rows that exist; a miss means the
                // hierarchy changed mid-run. Keep the old "missing unit →
                // continue" behaviour instead of reporting it as a zero row.
                continue;
            }

            $openTickets = (int) ($ticketCounts[$unitId] ?? 0);
            $openTodos = (int) ($todoCounts[$unitId] ?? 0);

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
