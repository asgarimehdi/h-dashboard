<?php

namespace App\Jobs;

use App\Services\ZabbixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            Log::warning('SyncZabbixJob: Zabbix item IDs not configured. Skipping.');

            return;
        }

        $out = $zabbix->getInterfaceTraffic($outItemId);
        $in = $zabbix->getInterfaceTraffic($inItemId);
        $traffic = array_merge($out, $in);

        Cache::put('zabbix_traffic_data', $traffic, now()->addMinutes(5));

        Log::info('SyncZabbixJob: cached '.count($traffic).' traffic records.');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncZabbixJob failed: '.$exception->getMessage());
    }
}
