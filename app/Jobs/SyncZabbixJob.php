<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\ZabbixSyncLog;
use App\Services\NotificationService;
use App\Services\ZabbixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncZabbixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 2;

    public function handle(ZabbixService $zabbix): void
    {
        $outItemId = config('services.zabbix.out_item_id');
        $inItemId = config('services.zabbix.in_item_id');

        if (empty($outItemId) || empty($inItemId)) {
            // The unconfigured-skip used to be a warning nobody read, so a
            // misconfigured deployment stayed silently broken for weeks
            // (#740). It now records a failed run like any other — the
            // dashboard shows it and the streak can fire the admin alert.
            Log::warning('SyncZabbixJob: Zabbix item IDs not configured. Skipping.');
            $this->recordFailure('Zabbix item IDs not configured');

            return;
        }

        $out = $zabbix->getInterfaceTraffic($outItemId);
        $in = $zabbix->getInterfaceTraffic($inItemId);
        $traffic = array_merge($out, $in);

        Cache::put('zabbix_traffic_data', $traffic, now()->addMinutes(5));

        Log::info('SyncZabbixJob: cached '.count($traffic).' traffic records.');

        $this->recordSuccess();
    }

    /**
     * Runs only after the queue gives up (tries exhausted), so one job
     * failure produces exactly one failure row — retries do not inflate
     * the consecutive-failure streak.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SyncZabbixJob failed: '.$exception->getMessage());

        // Structured reporting alongside the log line: if the log channel
        // itself is down, the exception handler still records it (#740).
        report($exception);

        $this->recordFailure($exception->getMessage());
    }

    private function recordSuccess(): void
    {
        (new ZabbixSyncLog([
            'success' => true,
            'consecutive_failures' => 0,
            'ran_at' => now(),
        ]))->save();
    }

    private function recordFailure(string $error): void
    {
        $previous = ZabbixSyncLog::query()->orderByDesc('id')->first();
        $streak = ($previous !== null && ! $previous->success)
            ? $previous->consecutive_failures + 1
            : 1;

        (new ZabbixSyncLog([
            'success' => false,
            'consecutive_failures' => $streak,
            'error' => $error,
            'ran_at' => now(),
        ]))->save();

        if ($streak === ZabbixSyncLog::ALERT_THRESHOLD) {
            $this->alertAdmins($error);
        }
    }

    /**
     * The alert channel decided in #740: an in-app notification for every
     * admin user through the existing NotificationService — no new
     * infrastructure (email/webhook). Sent once when the streak REACHES the
     * threshold; further failures stay visible on the dashboard without
     * spamming, and any success resets the streak.
     */
    private function alertAdmins(string $error): void
    {
        foreach (User::query()->whereRelation('roles', 'name', 'admin')->pluck('id') as $userId) {
            NotificationService::send(
                (int) $userId,
                'zabbix_sync',
                'هشدار: sync زیبکس سه بار متوالی ناموفق بود',
                'خطای آخر: '.$error,
                'o-bell',
                'text-error',
                '/dashboard'
            );
        }
    }
}
