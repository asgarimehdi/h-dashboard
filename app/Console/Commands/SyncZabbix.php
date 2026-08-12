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

        try {
            $traffic = $zabbix->getInterfaceTraffic();
            $this->line("  Fetched ".count($traffic)." traffic records.");
        } catch (\Throwable $e) {
            $this->warn('Zabbix sync failed: '.$e->getMessage());

            return 1;
        }

        $this->info('Zabbix sync complete.');

        return 0;
    }
}
