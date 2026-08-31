<?php

namespace Tests\Feature;

use App\Models\MaintenanceSchedule;
use App\Models\Ticket;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Issue #533: running the command twice should not create duplicate tickets
     * when an open ticket already exists for the same schedule.
     */
    public function test_does_not_create_duplicate_when_open_ticket_exists(): void
    {
        $unit = Unit::create(['name' => 'Ward D']);
        $schedule = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Fire alarm test',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        // First run — creates a ticket
        $this->artisan('maintenance:generate-due')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 1);

        // Manually reset the schedule to make it due again (simulates scheduler overlap)
        $schedule->update(['next_due_at' => now()->subDay(), 'last_generated_at' => now()->subDay()]);

        // Second run — should SKIP (open ticket already exists), no duplicate created
        $this->artisan('maintenance:generate-due')
            ->assertExitCode(0);

        // Still only 1 ticket — no duplicate
        $this->assertDatabaseCount('tickets', 1);
        // Schedule should still be advanced even though we skipped
        $schedule->refresh();
        $this->assertTrue($schedule->next_due_at->isFuture());
    }

    /**
     * Issue #533: after the existing ticket is completed, a new one CAN be created.
     */
    public function test_creates_new_ticket_after_existing_is_completed(): void
    {
        $unit = Unit::create(['name' => 'Ward E']);
        $schedule = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Generator check',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        // First run
        $this->artisan('maintenance:generate-due')->assertExitCode(0);
        $this->assertDatabaseCount('tickets', 1);

        // Complete the existing ticket
        Ticket::where('subject', 'Generator check')->update(['status' => 'completed']);

        // Reset schedule — use DB directly to avoid Eloquent model caching issues
        DB::table('maintenance_schedules')
            ->where('id', $schedule->id)
            ->update([
                'next_due_at' => now()->subDay(),
                'last_generated_at' => now()->subDay(),
            ]);

        // Second run — should create a NEW ticket (old one is completed)
        $this->artisan('maintenance:generate-due')->assertExitCode(0);
        $this->assertDatabaseCount('tickets', 2);
    }
}
