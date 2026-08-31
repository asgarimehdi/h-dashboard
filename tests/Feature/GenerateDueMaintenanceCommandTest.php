<?php

namespace Tests\Feature;

use App\Models\MaintenanceSchedule;
use App\Models\Ticket;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

covers(\App\Console\Commands\GenerateDueMaintenance::class);

class GenerateDueMaintenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_schedule_creates_ticket_and_bumps_next_due(): void
    {
        $unit = Unit::create(['name' => 'Ward A']);
        $schedule = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'HVAC inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 1 maintenance ticket(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 1);
        $ticket = Ticket::first();
        $this->assertEquals('HVAC inspection', $ticket->subject);
        $this->assertEquals('created', $ticket->status);

        $schedule->refresh();
        $this->assertNotNull($schedule->last_generated_at);
        $this->assertNotNull($schedule->next_due_at);
    }

    public function test_not_yet_due_schedule_is_skipped(): void
    {
        $unit = Unit::create(['name' => 'Ward B']);
        MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Future check',
            'frequency' => 'monthly',
            'next_due_at' => now()->addDays(10),
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Found 0 maintenance schedule(s) due')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_dry_run_creates_no_ticket(): void
    {
        $unit = Unit::create(['name' => 'Ward C']);
        MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'HVAC inspection',
            'frequency' => 'monthly',
            'next_due_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:generate-due', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 0);
    }
}
