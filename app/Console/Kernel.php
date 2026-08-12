<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Cache maintenance — hourly
        $schedule->command('cache:prune-stale')->hourly();

        // Recurring todos — daily at 02:00 Tehran time
        $schedule->command('todos:generate-recurring')->dailyAt('02:00');

        // Maintenance tasks — daily at 03:00 Tehran time
        $schedule->command('maintenance:generate-due')->dailyAt('03:00');

        // Data archival — weekly Monday 04:00 Tehran time
        $schedule->command('data:archive')->weeklyOn(1, '04:00');

        // Report generation — daily at 06:00 Tehran time
        $schedule->command('reports:generate-daily')->dailyAt('06:00');

        // Zabbix sync — every 5 minutes
        $schedule->command('zabbix:sync')->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
