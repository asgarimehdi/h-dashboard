<?php

namespace App\Livewire\It\Zabbix;

use App\Models\ZabbixHost;
use App\Models\ZabbixItem;
use App\Models\ZabbixItemPair;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('جفترهای زابیکس')]
class Pairs extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 15;
    public $hostFilter = null;

    public function render()
    {
        $hosts = ZabbixHost::select('id', 'visible_name')->orderBy('visible_name')->get();

        $pairs = ZabbixItemPair::with([
            'host.unit',
            'outItem.host',
            'inItem.host',
        ])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                      ->orWhere('description', 'like', "%{$this->search}%");
                });
            })
            ->when($this->hostFilter, function ($query) {
                $query->where('zabbix_host_id', $this->hostFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.it.zabbix.pairs', compact('pairs', 'hosts'));
    }
}