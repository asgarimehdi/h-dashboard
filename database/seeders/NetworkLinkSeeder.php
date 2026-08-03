<?php

namespace Database\Seeders;

use App\Models\Hardware;
use App\Models\NetworkLink;
use Illuminate\Database\Seeder;

class NetworkLinkSeeder extends Seeder
{
    public function run(): void
    {
        $switchGroups = Hardware::with('person.unit')
            ->whereNotNull('switch')
            ->where('switch', '!=', '')
            ->get()
            ->groupBy('switch');

        $switchUnits = $switchGroups->map(function ($group) {
            $first = $group->first();
            return [
                'name' => $group->first()->switch,
                'unit_id' => $first->person->unit?->id,
                'net_type' => $first->net_type ?? 'unknown',
            ];
        })->toArray();

        $links = [];

        // Create fiber links between switches (star topology from sw-core)
        $core = collect($switchUnits)->firstWhere('name', 'sw-core');
        $others = collect($switchUnits)->reject(fn($s) => $s['name'] === 'sw-core');

        foreach ($others as $sw) {
            if ($core) {
                $links[] = [
                    'source_switch' => 'sw-core',
                    'target_switch' => $sw['name'],
                    'link_type' => 'fiber',
                    'vlans' => ['10', '20'],
                    'distance_km' => round(rand(50, 500) / 100, 2),
                    'latency_ms' => rand(1, 5),
                    'bandwidth_mbps' => 1000,
                    'is_redundant' => in_array($sw['name'], ['sw-a', 'sw-b']),
                    'source_unit_id' => $core['unit_id'],
                    'target_unit_id' => $sw['unit_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Add some wireless links between nearby switches
        $pairs = [['sw-a', 'sw-b'], ['sw-c', 'sw-e'], ['sw-f', 'sw-11']];
        foreach ($pairs as [$src, $tgt]) {
            $srcSw = collect($switchUnits)->firstWhere('name', $src);
            $tgtSw = collect($switchUnits)->firstWhere('name', $tgt);
            if ($srcSw && $tgtSw) {
                $links[] = [
                    'source_switch' => $src,
                    'target_switch' => $tgt,
                    'link_type' => 'wireless',
                    'vlans' => ['30'],
                    'distance_km' => round(rand(100, 300) / 100, 2),
                    'latency_ms' => rand(5, 15),
                    'bandwidth_mbps' => 100,
                    'is_redundant' => false,
                    'source_unit_id' => $srcSw['unit_id'],
                    'target_unit_id' => $tgtSw['unit_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Add a VPN link
        $src12 = collect($switchUnits)->firstWhere('name', 'sw-12');
        $src13 = collect($switchUnits)->firstWhere('name', 'sw-13');
        if ($src12 && $src13) {
            $links[] = [
                'source_switch' => 'sw-12',
                'target_switch' => 'sw-13',
                'link_type' => 'vpn',
                'vlans' => ['100'],
                'distance_km' => round(rand(1000, 5000) / 100, 2),
                'latency_ms' => rand(20, 80),
                'bandwidth_mbps' => 50,
                'is_redundant' => false,
                'source_unit_id' => $src12['unit_id'],
                'target_unit_id' => $src13['unit_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($links)) {
            foreach ($links as $link) {
                NetworkLink::create($link);
            }
            $this->command->info("Created " . count($links) . " network links.");
        } else {
            $this->command->warn("No switches found to create network links.");
        }
    }
}