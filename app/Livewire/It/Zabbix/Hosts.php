<?php

namespace App\Livewire\It\Zabbix;

use App\Models\ZabbixHost;
use App\Models\ZabbixItem;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('هاست‌های زابیکس')]
class Hosts extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 15;

    public function render()
    {
        $hosts = ZabbixHost::with(['unit', 'items'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('host_name', 'like', "%{$this->search}%")
                      ->orWhere('visible_name', 'like', "%{$this->search}%")
                      ->orWhere('ip', 'like', "%{$this->search}%")
                      ->orWhere('description', 'like', "%{$this->search}%");
                });
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.it.zabbix.hosts', compact('hosts'));
    }
}