<?php

namespace App\Jobs;

use App\Models\ZabbixSyncLog;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixResult;
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

    public function handle(ZabbixClient $zabbix): void
    {
        $outItemId = config('services.zabbix.out_item_id');
        $inItemId = config('services.zabbix.in_item_id');

        if (empty($outItemId) || empty($inItemId)) {
            // The unconfigured-skip used to be a warning nobody read, so a
            // misconfigured deployment stayed silently broken for weeks
            // (#740). It now records a failed run like any other — the
            // dashboard shows it.
            Log::warning('SyncZabbixJob: Zabbix item IDs not configured. Skipping.');
            $this->recordFailure('Zabbix item IDs not configured');

            return;
        }

        $out = $zabbix->traffic($outItemId);
        if ($out->failed()) {
            $this->recordTransportFailure('out', $out);

            return;
        }

        $in = $zabbix->traffic($inItemId);
        if ($in->failed()) {
            $this->recordTransportFailure('in', $in);

            return;
        }

        $traffic = array_merge((array) $out->data(), (array) $in->data());

        Cache::put('zabbix_traffic_data', $traffic, now()->addMinutes(5));

        Log::info('SyncZabbixJob: cached '.count($traffic).' traffic records.');

        $this->recordSuccess();
    }

    /**
     * Runs only after the queue gives up (tries exhausted), so one job
     * failure produces exactly one failure row — retries do not produce
     * extra failure rows.
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
            'ran_at' => now(),
        ]))->save();
    }

    private function recordFailure(string $error): void
    {
        // Append-only: one row per run; nothing derives a value from
        // prior rows, so a duplicate row for one outage is harmless.
        (new ZabbixSyncLog([
            'success' => false,
            'error' => $error,
            'ran_at' => now(),
        ]))->save();
    }

    /**
     * A failed traffic fetch is an ordinary value, not an exception, so it is
     * recorded here rather than in failed(): one row per run and no retry —
     * the next scheduler tick (5 minutes later) is the retry.
     */
    private function recordTransportFailure(string $direction, ZabbixResult $result): void
    {
        $error = sprintf(
            'Zabbix %s traffic fetch failed (%s): %s',
            $direction,
            $result->failure() ?? 'error',
            $result->message() ?? 'unknown error',
        );

        Log::error('SyncZabbixJob: '.$error);

        $this->recordFailure($error);
    }
}
