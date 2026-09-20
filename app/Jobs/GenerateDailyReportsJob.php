<?php

namespace App\Jobs;

use App\Console\Commands\GenerateDailyReports;
use App\Services\AccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDailyReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $command = app(GenerateDailyReports::class);
        $access = app(AccessService::class);
        $command->handle($access);

        Log::info('GenerateDailyReportsJob: completed');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateDailyReportsJob failed: '.$exception->getMessage());
    }
}
