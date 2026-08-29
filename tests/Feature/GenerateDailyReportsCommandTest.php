<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateDailyReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_generated_for_unit_filter(): void
    {
        $unit = Unit::create(['name' => 'Ward A']);
        Ticket::create([
            'ticket_code' => 'T-'.strtoupper(\Illuminate\Support\Str::random(8)),
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
}
