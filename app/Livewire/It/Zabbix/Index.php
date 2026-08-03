<?php

namespace App\Livewire\It\Zabbix;

use App\Models\ZabbixHost;
use App\Models\ZabbixItem;
use App\Models\ZabbixItemPair;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

#[Layout('components.layouts.app')]
#[Title('کانفیگ زابیکس')]
class Index extends Component
{
    public function render()
    {
        $stats = [
            'hosts_count' => ZabbixHost::count(),
            'items_count' => ZabbixItem::count(),
            'pairs_count' => ZabbixItemPair::count(),
            'units_with_hosts' => ZabbixHost::select('unit_id')->distinct()->count(),
        ];

        $recentHosts = ZabbixHost::with('unit')
            ->latest()
            ->take(5)
            ->get();

        return view('livewire.it.zabbix.index', compact('stats', 'recentHosts'));
    }
}