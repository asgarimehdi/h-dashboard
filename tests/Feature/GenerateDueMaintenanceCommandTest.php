<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateDueMaintenance;
use App\Models\MaintenanceSchedule;
use App\Models\Ticket;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

covers(GenerateDueMaintenance::class);

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

        // #906: the command writes no `user_id`, and `tickets.user_id` is
        // nullable on purpose. This is what makes the ticket creator cell
        // resolve to null on /tickets/inbox and /tickets/monitoring — pin the
        // contract so the null-safe cell stays load-bearing instead of
        // incidental.
        $this->assertNull($ticket->user_id);

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

    /**
     * Issue #876 (guard): a unit-less schedule must be skipped and counted,
     * never crash the run with a 23502 — and the valid schedules in the same
     * batch must still be processed.
     */
    public function test_unit_less_schedule_is_skipped_and_valid_schedule_still_processed(): void
    {
        $unit = Unit::create(['name' => 'Ward F']);
        $valid = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'HVAC inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);
        $unitLess = MaintenanceSchedule::create([
            'unit_id' => null,
            'title' => 'Org-wide check',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Skipping 1 schedule(s) without a unit')
            ->expectsOutputToContain('Created 1 maintenance ticket(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 1);
        $this->assertEquals('HVAC inspection', Ticket::first()->subject);

        $valid->refresh();
        $this->assertNotNull($valid->last_generated_at);

        // The skipped schedule is not advanced — it stays visibly due (and
        // visibly skipped) until the product rule for it is decided.
        $unitLess->refresh();
        $this->assertNull($unitLess->last_generated_at);
    }

    /**
     * Issue #876 (guard): deleting a unit turns its schedules unit-less via
     * the FK (on delete set null). That manufactured poison row must not
     * take the nightly run down with it.
     */
    public function test_schedule_orphaned_by_unit_delete_does_not_poison_the_run(): void
    {
        $unitA = Unit::create(['name' => 'Ward G']);
        $unitB = Unit::create(['name' => 'Ward H']);
        $kept = MaintenanceSchedule::create([
            'unit_id' => $unitA->id,
            'title' => 'Kept schedule',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);
        $orphaned = MaintenanceSchedule::create([
            'unit_id' => $unitB->id,
            'title' => 'Orphaned schedule',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        $unitB->delete();
        $orphaned->refresh();
        $this->assertNull($orphaned->unit_id);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 1 maintenance ticket(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 1);
        $this->assertEquals('Kept schedule', Ticket::first()->subject);

        $kept->refresh();
        $this->assertNotNull($kept->last_generated_at);
        $orphaned->refresh();
        $this->assertNull($orphaned->last_generated_at);
    }

    /**
     * Issue #876 (isolation): a schedule that genuinely fails must surface a
     * non-zero exit code (so cron can alert) without starving the others.
     */
    public function test_failing_schedule_returns_non_zero_and_does_not_starve_others(): void
    {
        $unit = Unit::create(['name' => 'Ward I']);
        $good = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Good schedule',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);
        $bad = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Exploding schedule',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        Ticket::creating(function (Ticket $ticket) {
            if ($ticket->subject === 'Exploding schedule') {
                throw new \RuntimeException('boom');
            }
        });

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 1 maintenance ticket(s)')
            ->assertExitCode(1);

        $this->assertDatabaseCount('tickets', 1);
        $this->assertEquals('Good schedule', Ticket::first()->subject);

        $good->refresh();
        $this->assertNotNull($good->last_generated_at);

        // The failed schedule rolled back and was not advanced — it stays
        // due and retryable instead of half-applied.
        $bad->refresh();
        $this->assertNull($bad->last_generated_at);
    }
}
