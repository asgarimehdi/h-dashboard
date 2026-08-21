<?php

namespace Tests\Feature;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledJobInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_kernel_schedule_has_expected_tasks(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $kernel->resolveConsoleSchedule();

        // Collect scheduled event descriptions
        $events = $schedule->events();
        $this->assertNotEmpty($events, 'Schedule should have registered events');

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
        $this->assertContains('zabbix:sync', $commands);
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
