<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

// A report run can take 600s, so it goes on the connection whose `retry_after`
// (660s) outlives this job's $timeout. The default `redis` connection reserves a
// job for only 90s, which would let a second worker re-run the whole report —
// it writes rows (#1010).
#[Connection('redis-reports')]
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
