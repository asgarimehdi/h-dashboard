<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateDailyReports;
use App\Models\DailyReport;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

covers(GenerateDailyReports::class);

class GenerateDailyReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_generated_for_unit_filter(): void
    {
        $unit = Unit::create(['name' => 'Ward A']);
        Ticket::create([
            'ticket_code' => 'T-'.strtoupper(Str::random(8)),
            'subject' => 't',
            'content' => 'c',
            'status' => 'created',
            'unit_id' => $unit->id,
        ]);
        Todo::create([
            'title' => 'todo',
            'start_at' => now(),
            'is_completed' => false,
            'unit_id' => $unit->id,
        ]);

        $this->artisan('reports:generate-daily', ['--unit' => $unit->id])
            ->expectsOutputToContain('Generated 1 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 1);
        $report = DailyReport::first();
        $this->assertEquals($unit->id, $report->unit_id);
        $this->assertStringContainsString('open tickets', $report->summary);
    }

    public function test_report_not_duplicated_same_day(): void
    {
        $unit = Unit::create(['name' => 'Ward B']);
        $this->artisan('reports:generate-daily', ['--unit' => $unit->id])->assertExitCode(0);
        $this->artisan('reports:generate-daily', ['--unit' => $unit->id])
            ->expectsOutputToContain('Generated 0 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 1);
    }

    public function test_dry_run_creates_no_report(): void
    {
        $unit = Unit::create(['name' => 'Ward C']);

        $this->artisan('reports:generate-daily', ['--unit' => $unit->id, '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 0);
    }

    /**
     * Issue #836: the scheduler runs this command with no authenticated user
     * and no `--unit`, so an empty scope must mean "every unit", not "none".
     */
    public function test_report_generated_for_all_units_without_authenticated_user(): void
    {
        Session::flush();
        $first = Unit::create(['name' => 'Ward X']);
        $second = Unit::create(['name' => 'Ward Y']);

        $this->assertNull(auth()->user(), 'Precondition: no authenticated user.');

        $this->artisan('reports:generate-daily')
            ->expectsOutputToContain('Generated 2 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 2);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            DailyReport::pluck('unit_id')->all()
        );
    }

    public function test_unit_filter_still_scopes_to_one_unit(): void
    {
        $target = Unit::create(['name' => 'Ward Target']);
        Unit::create(['name' => 'Ward Other']);

        $this->artisan('reports:generate-daily', ['--unit' => $target->id])
            ->expectsOutputToContain('Generated 1 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 1);
        $this->assertEquals($target->id, DailyReport::first()->unit_id);
    }
}
