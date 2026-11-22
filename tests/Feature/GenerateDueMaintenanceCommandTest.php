<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateDueMaintenance;
use App\Models\MaintenanceSchedule;
use App\Models\Ticket;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(GenerateDueMaintenance::class);

class GenerateDueMaintenanceCommandTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
     * Issue #1610: a rerun that starts after the first run committed must take
     * the "open ticket already exists" branch — no second ticket — and must
     * still advance the schedule so it cannot get stuck due forever.
     */
    public function test_rerun_after_a_committed_run_creates_no_second_ticket(): void
    {
        $unit = Unit::create(['name' => 'Ward J']);
        $schedule = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Elevator service',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 1 maintenance ticket(s)')
            ->assertExitCode(0);

        // Put the schedule back in the exact state a scheduler overlap leaves
        // behind: due again while the first run's ticket is still open.
        DB::table('maintenance_schedules')
            ->where('id', $schedule->id)
            ->update(['next_due_at' => now()->subDay()]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain("Skipping schedule #{$schedule->id} — open ticket already exists")
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 1);

        $schedule->refresh();
        $this->assertTrue($schedule->next_due_at->isFuture());
    }

    /**
     * Issue #1610: overlapping runs must create exactly one ticket.
     *
     * The guard (`Ticket::exists()`) and the insert are two statements, so a
     * run that starts after another run passed its guard — but before it
     * committed — still sees "no open ticket" and writes a second one. The row
     * lock on the schedule, taken inside the per-schedule transaction, is what
     * serialises the pair: the second run waits for the first to commit, then
     * its guard reads that committed ticket and skips.
     *
     * The interleaving is forced rather than hoped for, with two real database
     * sessions and a handshake (no poll-and-hope for the lock to appear):
     *
     *  - run 1 (its own process, raw PDO) opens a transaction, takes the
     *    schedule row with `SELECT ... FOR UPDATE`, inserts its ticket, prints
     *    "locked", then commits — the exact state a scheduler overlap leaves
     *    behind: guard passed, ticket written, nothing committed yet. The
     *    trailing `usleep` is only a failsafe so the holder commits and exits
     *    on its own instead of relying on the test's `finally` to kill it;
     *  - run 2 is the real command on the test's connection, started only
     *    after run 1 reports "locked".
     *
     * With the fix run 2 waits for run 1 to commit, then sees the open ticket
     * and skips. Without it run 2 never waits on the guard: it writes its own
     * ticket, and the schedule ends up with two tickets for one occurrence.
     *
     * Holding the row lock is what proves the pair atomic: the lock is the
     * only thing between run 2's guard and its insert, so a run 2 that cannot
     * pass the lock cannot insert before run 1 commits.
     *
     * The fixture is created over a committed side connection (not the test's
     * wrapping transaction) because the second session can only see committed
     * rows; it is removed in `finally` so later tests in this class keep
     * counting from an empty `tickets` table.
     */
    public function test_overlapping_runs_create_exactly_one_ticket(): void
    {
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        DB::purge('pgsql_race');
        $side = DB::connection('pgsql_race');

        $holder = null;
        $unitId = null;
        $scheduleId = null;

        $holderCode = <<<'PHP'
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $argv[1], $argv[2], $argv[3]),
    $argv[4],
    $argv[5],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->beginTransaction();
$pdo->prepare('select id from maintenance_schedules where id = ? for update')->execute([$argv[6]]);
$pdo->prepare('insert into tickets (ticket_code, subject, content, status, priority, unit_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?, now(), now())')
    ->execute([$argv[7], 'Race inspection', 'Generated from maintenance schedule #'.$argv[6], 'created', 'normal', $argv[8]]);
echo "locked\n";
flush();
usleep(2_000_000);
$pdo->commit();
PHP;

        try {
            $unitId = $side->table('units')->insertGetId(['name' => 'Race Ward']);
            $scheduleId = $side->table('maintenance_schedules')->insertGetId([
                'unit_id' => $unitId,
                'title' => 'Race inspection',
                'frequency' => 'monthly',
                'recurrence_interval' => 1,
                'next_due_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $connection = config('database.connections.pgsql');

            $holder = new Process([
                PHP_BINARY,
                '-r',
                $holderCode,
                (string) $connection['host'],
                (string) $connection['port'],
                (string) $connection['database'],
                (string) $connection['username'],
                (string) $connection['password'],
                (string) $scheduleId,
                'T-'.strtoupper(bin2hex(random_bytes(4))),
                (string) $unitId,
            ], base_path());
            $holder->setTimeout(30);
            $holder->start();

            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && ! str_contains($holder->getOutput(), 'locked')) {
                if (! $holder->isRunning()) {
                    break;
                }
                usleep(20_000);
            }

            $this->assertStringContainsString(
                'locked',
                $holder->getOutput(),
                'The first run never took the schedule row lock: '.$holder->getErrorOutput(),
            );

            // Run 2: the real command, on the test's connection, while run 1
            // still holds the row lock.
            $this->artisan('maintenance:generate-due')->assertExitCode(0);

            $holderExitCode = $holder->wait();
            $this->assertSame(0, $holderExitCode, 'The first run failed: '.$holder->getErrorOutput());

            $this->assertSame(
                1,
                Ticket::where('unit_id', $unitId)->where('subject', 'Race inspection')->count(),
                'Two overlapping runs wrote two maintenance tickets for one schedule (issue #1610).',
            );
        } finally {
            $holder?->stop(1);

            // Release this test's wrapping transaction before the cleanup:
            // the command advanced the schedule row inside it, and the side
            // connection would block on that row lock until the test returned.
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($scheduleId !== null) {
                $side->table('maintenance_schedules')->where('id', $scheduleId)->delete();
            }

            if ($unitId !== null) {
                $side->table('tickets')->where('unit_id', $unitId)->delete();
            }

            if ($unitId !== null) {
                $side->table('units')->where('id', $unitId)->delete();
            }

            DB::purge('pgsql_race');
        }
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

    /**
     * Issue #954: this command is the only UNATTENDED priority writer in the
     * app. Its output must land inside `Ticket::PRIORITIES` — the nightly job
     * used to write `medium`, which the DB CHECK accepts but no bucket,
     * chart, calendar map or validation rule reads, so every generated
     * ticket was invisible to the reporting it exists for.
     */
    public function test_generated_ticket_priority_is_a_reported_bucket(): void
    {
        $unit = Unit::create(['name' => 'Ward F']);
        MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Priority contract check',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:generate-due')->assertExitCode(0);

        $ticket = Ticket::firstOrFail();
        $this->assertContains($ticket->priority, Ticket::PRIORITIES);
        // `normal` is the honest mapping: matters more than `low`, less than
        // a person's `urgent` request — and it is a bucket every report has.
        $this->assertSame('normal', $ticket->priority);
    }

    /**
     * Issue #1623: a monthly schedule created on the 31st must advance to
     * February 28, not overflow to March 3 and skip February entirely.
     */
    public function test_monthly_schedule_advanced_on_31st_sets_next_due_to_feb_28(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 08:00:00'));

        $unit = Unit::create(['name' => 'Ward K']);
        $schedule = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Month-end inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => '2026-01-31 08:00:00',
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 1 maintenance ticket(s)')
            ->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame('2026-02-28', $schedule->next_due_at?->toDateString());
    }

    /**
     * Issue #1623: the step is anchored on the schedule's creation day, so a
     * late run (due Jan 31, executed Feb 2) still lands on February 28 instead
     * of imposing the run day (March 2) on the next due date.
     */
    public function test_monthly_schedule_late_run_anchors_on_creation_not_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-02 08:00:00'));

        $unit = Unit::create(['name' => 'Ward L']);
        $schedule = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Late month-end inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => '2026-01-31 08:00:00',
        ]);
        // The schedule was created on the 31st; only the run is late.
        DB::table('maintenance_schedules')
            ->where('id', $schedule->id)
            ->update(['created_at' => '2026-01-31 08:00:00']);

        $this->artisan('maintenance:generate-due')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame('2026-02-28', $schedule->next_due_at?->toDateString());
    }

    /**
     * Issue #1623: a run that missed several months must catch up to the first
     * future occurrence, keeping the 31st anchor (Feb 28 → Mar 31 → Apr 30 →
     * May 31) instead of leaving the schedule due forever.
     */
    /**
     * Issue #1640: two schedules with the same title in the same unit are two
     * independent schedules. The guard must key on the schedule that produced
     * the ticket, not on its content, or the second schedule is silently
     * starved every cycle while its `next_due_at` still advances.
     */
    public function test_two_same_title_schedules_in_same_unit_both_generate(): void
    {
        $unit = Unit::create(['name' => 'Ward N']);
        $scheduleA = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Quarterly inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);
        $scheduleB = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Quarterly inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 2 maintenance ticket(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 2);

        $this->assertEqualsCanonicalizing(
            [$scheduleA->id, $scheduleB->id],
            Ticket::all()->pluck('schedule_id')->all(),
        );
    }

    /**
     * Issue #1640 (legacy fallback): a ticket written before the identity
     * column existed carries `schedule_id = null`. The unit/subject fallback
     * must still stop the same cycle from producing a duplicate — the fallback
     * is a permanent safety net, not dead code.
     */
    public function test_legacy_ticket_without_schedule_id_still_blocks_regeneration(): void
    {
        $unit = Unit::create(['name' => 'Ward O']);
        $schedule = MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Legacy check',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        Ticket::create([
            'ticket_code' => 'T-LEGACY01',
            'subject' => 'Legacy check',
            'content' => 'Pre-migration ticket',
            'priority' => 'normal',
            'status' => 'created',
            'unit_id' => $unit->id,
            // `schedule_id` intentionally left null: a pre-migration row.
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 0 maintenance ticket(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 1);

        $schedule->refresh();
        $this->assertNotNull($schedule->last_generated_at);
        $this->assertTrue($schedule->next_due_at->isFuture());
    }

    /**
     * Issue #1640: the same title in two different units was already two
     * independent schedules — pin it so the identity guard cannot regress it.
     */
    public function test_two_schedules_same_title_different_units_both_generate(): void
    {
        $unitA = Unit::create(['name' => 'Ward P']);
        $unitB = Unit::create(['name' => 'Ward Q']);
        $scheduleA = MaintenanceSchedule::create([
            'unit_id' => $unitA->id,
            'title' => 'Shared inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);
        $scheduleB = MaintenanceSchedule::create([
            'unit_id' => $unitB->id,
            'title' => 'Shared inspection',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:generate-due')
            ->expectsOutputToContain('Created 2 maintenance ticket(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tickets', 2);
        $this->assertEqualsCanonicalizing(
            [$scheduleA->id, $scheduleB->id],
            Ticket::all()->pluck('schedule_id')->all(),
        );
    }
}
