<?php

namespace App\Livewire\It\Zabbix;

use App\Models\ZabbixHost;
use App\Models\ZabbixItem;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('آیتم‌های زابیکس')]
class Items extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 15;
    public $hostFilter = null;

    public function render()
    {
        $hosts = ZabbixHost::select('id', 'visible_name')->orderBy('visible_name')->get();

        $items = ZabbixItem::with(['host.unit'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                      ->orWhere('item_key', 'like', "%{$this->search}%")
                      ->orWhere('type', 'like', "%{$this->search}%");
                });
            })
            ->when($this->hostFilter, function ($query) {
                $query->where('zabbix_host_id', $this->hostFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.it.zabbix.items', compact('items', 'hosts'));
    }
}