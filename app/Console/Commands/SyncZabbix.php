<?php

namespace App\Console\Commands;

use App\Services\Zabbix\ZabbixClient;
use Illuminate\Console\Command;

class SyncZabbix extends Command
{
    protected $signature = 'zabbix:sync';

    /**
     * @deprecated Use \App\Jobs\SyncZabbixJob dispatched via the scheduler instead.
     */
    protected $description = 'Sync metrics from Zabbix monitoring system (deprecated — use SyncZabbixJob)';

    public function handle(ZabbixClient $zabbix): int
    {
        $this->info('Syncing Zabbix metrics...');

        $outItemId = config('services.zabbix.out_item_id');
        $inItemId = config('services.zabbix.in_item_id');

        if (empty($outItemId) || empty($inItemId)) {
            $this->warn('Zabbix item IDs not configured (ZABBIX_OUT_ITEM_ID / ZABBIX_IN_ITEM_ID). Skipping sync.');

            return 1;
        }

        $out = $zabbix->traffic($outItemId);
        if ($out->failed()) {
            $this->warn("Zabbix sync failed (out {$out->failure()}): {$out->message()}");

            return 1;
        }

        $in = $zabbix->traffic($inItemId);
        if ($in->failed()) {
            $this->warn("Zabbix sync failed (in {$in->failure()}): {$in->message()}");

            return 1;
        }

        $traffic = array_merge((array) $out->data(), (array) $in->data());
        $this->line('  Fetched '.count($traffic).' traffic records.');

        $this->info('Zabbix sync complete.');

        return 0;
    }
}
