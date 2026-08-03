<?php

namespace Database\Seeders;

use App\Models\ZabbixHost;
use App\Models\ZabbixItem;
use App\Models\ZabbixItemPair;
use Illuminate\Database\Seeder;

class ZabbixConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Create sample Zabbix hosts
        $host1 = ZabbixHost::create([
            'unit_id' => 1,
            'host_id' => '10084',
            'host_name' => 'zabbix-server-01',
            'visible_name' => 'Zabbix Server 01',
            'ip' => '192.168.1.100',
            'description' => 'Main Zabbix monitoring server',
            'status' => 'active',
            'template_ids' => ['10001', '10002'],
        ]);

        $host2 = ZabbixHost::create([
            'unit_id' => 1,
            'host_id' => '10085',
            'host_name' => 'app-server-01',
            'visible_name' => 'App Server 01',
            'ip' => '192.168.1.101',
            'description' => 'Application server 1',
            'status' => 'active',
            'template_ids' => ['10003'],
        ]);

        $host3 = ZabbixHost::create([
            'unit_id' => 2,
            'host_id' => '10086',
            'host_name' => 'db-server-01',
            'visible_name' => 'DB Server 01',
            'ip' => '192.168.1.102',
            'description' => 'Database server 1',
            'status' => 'active',
            'template_ids' => ['10004'],
        ]);

        // Create sample Zabbix items
        $items = [
            [
                'zabbix_host_id' => $host1->id,
                'item_id' => '73638',
                'item_key' => 'system.cpu.util',
                'name' => 'CPU usage',
                'type' => 'cpu',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '30s',
                'is_monitored' => true,
                'display_order' => 1,
                'last_value' => ['value' => 15.5, 'clock' => time()],
                'last_check_at' => now(),
            ],
            [
                'zabbix_host_id' => $host1->id,
                'item_id' => '73639',
                'item_key' => 'vm.memory.size[pused]',
                'name' => 'Memory usage',
                'type' => 'memory',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '30s',
                'is_monitored' => true,
                'display_order' => 2,
                'last_value' => ['value' => 45.3, 'clock' => time()],
                'last_check_at' => now(),
            ],
            [
                'zabbix_host_id' => $host1->id,
                'item_id' => '73640',
                'item_key' => 'vfs.fs.size[/,pused]',
                'name' => 'Disk usage /',
                'type' => 'disk',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '60s',
                'is_monitored' => true,
                'display_order' => 3,
                'last_value' => ['value' => 62.0, 'clock' => time()],
                'last_check_at' => now(),
            ],
            [
                'zabbix_host_id' => $host2->id,
                'item_id' => '73641',
                'item_key' => 'system.cpu.util',
                'name' => 'CPU usage',
                'type' => 'cpu',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '30s',
                'is_monitored' => true,
                'display_order' => 1,
                'last_value' => ['value' => 25.0, 'clock' => time()],
                'last_check_at' => now(),
            ],
            [
                'zabbix_host_id' => $host2->id,
                'item_id' => '73642',
                'item_key' => 'vm.memory.size[pused]',
                'name' => 'Memory usage',
                'type' => 'memory',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '30s',
                'is_monitored' => true,
                'display_order' => 2,
                'last_value' => ['value' => 78.2, 'clock' => time()],
                'last_check_at' => now(),
            ],
            [
                'zabbix_host_id' => $host3->id,
                'item_id' => '73643',
                'item_key' => 'system.cpu.util',
                'name' => 'CPU usage',
                'type' => 'cpu',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '30s',
                'is_monitored' => true,
                'display_order' => 1,
                'last_value' => ['value' => 5.2, 'clock' => time()],
                'last_check_at' => now(),
            ],
            [
                'zabbix_host_id' => $host3->id,
                'item_id' => '73644',
                'item_key' => 'vm.memory.size[pused]',
                'name' => 'Memory usage',
                'type' => 'memory',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '30s',
                'is_monitored' => true,
                'display_order' => 2,
                'last_value' => ['value' => 82.1, 'clock' => time()],
                'last_check_at' => now(),
            ],
            [
                'zabbix_host_id' => $host3->id,
                'item_id' => '73645',
                'item_key' => 'vfs.fs.size[/data,pused]',
                'name' => 'Disk usage /data',
                'type' => 'disk',
                'unit' => '%',
                'value_type' => 'numeric_float',
                'delay' => '60s',
                'is_monitored' => true,
                'display_order' => 3,
                'last_value' => ['value' => 45.0, 'clock' => time()],
                'last_check_at' => now(),
            ],
        ];

        $createdItems = [];
        foreach ($items as $itemData) {
            $createdItems[] = ZabbixItem::create($itemData);
        }

        // Create sample item pairs (traffic pairs)
        // CPU + Memory for zabbix-server-01
        ZabbixItemPair::create([
            'zabbix_host_id' => $host1->id,
            'name' => 'CPU vs Memory - Zabbix Server',
            'out_item_id' => $createdItems[0]->id, // CPU zabbix-server-01
            'in_item_id' => $createdItems[1]->id, // Memory zabbix-server-01
            'unit_id' => 1,
            'description' => 'Traffic comparison between CPU and Memory on Zabbix Server',
            'is_active' => true,
            'display_order' => 1,
        ]);

        // CPU + Memory for app-server-01
        ZabbixItemPair::create([
            'zabbix_host_id' => $host2->id,
            'name' => 'CPU vs Memory - App Server',
            'out_item_id' => $createdItems[3]->id, // CPU app-server-01
            'in_item_id' => $createdItems[4]->id, // Memory app-server-01
            'unit_id' => 1,
            'description' => 'Traffic comparison between CPU and Memory on App Server',
            'is_active' => true,
            'display_order' => 2,
        ]);

        // CPU + Memory for db-server-01
        ZabbixItemPair::create([
            'zabbix_host_id' => $host3->id,
            'name' => 'CPU vs Memory - DB Server',
            'out_item_id' => $createdItems[5]->id, // CPU db-server-01
            'in_item_id' => $createdItems[6]->id, // Memory db-server-01
            'unit_id' => 2,
            'description' => 'Traffic comparison between CPU and Memory on DB Server',
            'is_active' => true,
            'display_order' => 3,
        ]);

        // CPU + Disk for db-server-01
        ZabbixItemPair::create([
            'zabbix_host_id' => $host3->id,
            'name' => 'CPU vs Disk /data - DB Server',
            'out_item_id' => $createdItems[5]->id, // CPU db-server-01
            'in_item_id' => $createdItems[7]->id, // Disk /data db-server-01
            'unit_id' => 2,
            'description' => 'Traffic comparison between CPU and Disk on DB Server',
            'is_active' => true,
            'display_order' => 4,
        ]);

        $this->command?->info('Zabbix configuration seeded successfully!');
    }
}