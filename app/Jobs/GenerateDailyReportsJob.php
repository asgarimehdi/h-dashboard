<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class GenerateDailyReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    /**
     * Execute the job.
     *
     * Runs the command through Artisan::call() rather than resolving the
     * command and calling handle() on it: a resolved Command has no console
     * input bound, so `$this->option(...)` inside handle() throws
     * `Call to a member function getOption() on null` (issue #836).
     * Artisan::call() binds the input properly and returns the exit code.
     */
    public function handle(): void
    {
        $exitCode = Artisan::call('reports:generate-daily');

        Log::info("GenerateDailyReportsJob: completed (exit {$exitCode})");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateDailyReportsJob failed: '.$exception->getMessage());
    }
}
