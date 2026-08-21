<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ZabbixService;

class SyncZabbix extends Command
{
    protected $signature = 'zabbix:sync';
    protected $description = 'Sync metrics from Zabbix monitoring system';

    public function handle(ZabbixService $zabbix): int
    {
        $this->info('Syncing Zabbix metrics...');

        $outItemId = config('services.zabbix.out_item_id');
        $inItemId = config('services.zabbix.in_item_id');

        if (empty($outItemId) || empty($inItemId)) {
            $this->warn('Zabbix item IDs not configured (ZABBIX_OUT_ITEM_ID / ZABBIX_IN_ITEM_ID). Skipping sync.');

            return 1;
        }

        try {
            $out = $zabbix->getInterfaceTraffic($outItemId);
            $in = $zabbix->getInterfaceTraffic($inItemId);
            $traffic = array_merge($out, $in);
            $this->line("  Fetched ".count($traffic)." traffic records.");
        } catch (\Throwable $e) {
            $this->warn('Zabbix sync failed: '.$e->getMessage());

            return 1;
        }

        $this->info('Zabbix sync complete.');

        return 0;
    }
}
