<?php

namespace Tests\Feature;

use App\Console\Commands\PruneStaleCache;
use App\Jobs\SyncZabbixJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

covers(PruneStaleCache::class);

class ScheduledJobInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Boot the console application the way `schedule:run` does.
     *
     * Issue #864, second layer: ApplicationBuilder::withSchedule() registers
     * its callback through Artisan::starting(), which only fires once the
     * console app is constructed. A bare make(Schedule::class) therefore
     * sees ZERO events even after the wiring is correct — which is why the
     * original test had to hand-resolve App\Console\Kernel to see anything at
     * all. Running any artisan command boots that app, so this is the public
     * path (no reflection into the protected getArtisan()).
     */
    private function bootConsoleSchedule(): void
    {
        Artisan::call('schedule:list');
    }

    /**
     * Issue #864: this must read the schedule the way `schedule:run` does —
     * off the container's Schedule::class binding, which is what
     * bootstrap/app.php's withSchedule() populates. The old version resolved
     * App\Console\Kernel by hand and called resolveConsoleSchedule() on it,
     * which dispatches to that object's own schedule() override: a code path
     * production never took, and it stayed green while
     * `schedule:list` reported no tasks at all.
     */
    public function test_scheduler_reads_expected_tasks_from_the_container(): void
    {
        $this->bootConsoleSchedule();

        $events = $this->app->make(Schedule::class)->events();

        $this->assertNotEmpty($events, 'the schedule must register events via bootstrap/app.php');

        $commands = [];
        foreach ($events as $event) {
            // Extract command name from full artisan command string
            $commandParts = explode(' ', $event->command);
            $commandName = end($commandParts);
            $commands[] = $commandName;
        }

        $this->assertContains('cache:prune-stale', $commands);
        $this->assertContains('todos:generate-recurring', $commands);
        $this->assertContains('maintenance:generate-due', $commands);
        $this->assertContains('data:archive', $commands);
        $this->assertContains('reports:generate-daily', $commands);

        // zabbix:sync is now dispatched as SyncZabbixJob, not an artisan command
        $descriptions = array_map(fn ($event) => $event->description ?? '', $events);
        $this->assertContains(SyncZabbixJob::class, $descriptions);
    }

    /**
     * The gate that would have caught this: any edit to bootstrap/app.php that
     * empties the schedule must fail here instead of silently disabling every
     * scheduled command.
     */
    public function test_schedule_never_regresses_to_zero_events(): void
    {
        $this->bootConsoleSchedule();

        $events = $this->app->make(Schedule::class)->events();

        $this->assertCount(
            6,
            $events,
            'the schedule must keep exactly the six registered items — zero means the wiring was dropped'
        );
    }

    /**
     * App\Console\Kernel must not come back: the framework binds the base
     * console kernel, so a re-added subclass would again be a dead file that
     * a hand-resolved test could pass on.
     */
    public function test_the_dead_console_kernel_is_not_the_bound_one(): void
    {
        $kernelClass = $this->app->make(Kernel::class)::class;

        $this->assertNotSame(
            'App\\Console\\Kernel',
            $kernelClass,
            'bootstrap/app.php must not restore the dead App\Console\Kernel'
        );
        $this->assertFalse(
            class_exists(\App\Console\Kernel::class),
            'app/Console/Kernel.php must stay deleted — the schedule lives in bootstrap/app.php now'
        );
    }

    public function test_prune_stale_cache_command_runs(): void
    {
        $exitCode = Artisan::call('cache:prune-stale', ['--dry-run' => true]);
        $this->assertEquals(0, $exitCode);
    }

    public function test_generate_recurring_todos_command_runs(): void
    {
        $exitCode = Artisan::call('todos:generate-recurring');
        $this->assertEquals(0, $exitCode);
    }

    public function test_generate_due_maintenance_command_runs(): void
    {
        $exitCode = Artisan::call('maintenance:generate-due');
        $this->assertEquals(0, $exitCode);
    }

    public function test_archive_old_records_command_runs(): void
    {
        $exitCode = Artisan::call('data:archive');
        $this->assertEquals(0, $exitCode);
    }

    public function test_generate_daily_reports_command_runs(): void
    {
        $exitCode = Artisan::call('reports:generate-daily');
        $this->assertEquals(0, $exitCode);
    }

    public function test_sync_zabbix_command_runs(): void
    {
        // ZabbixService will fail without real Zabbix - just verify command exists
        $exitCode = Artisan::call('zabbix:sync');
        // Command runs but may fail due to missing Zabbix connection - that's OK
        $this->assertNotNull($exitCode);
    }
}
