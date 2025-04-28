<?php

use App\Models\User;
use App\Models\LocationLog;
use Livewire\Volt\Component;

new class extends Component
{
    public $users;
    public $selectedUser = null;
    public $startDate;
    public $endDate;
    public $locationLogs = [];

    public function mount()
    {
        $this->users = User::orderBy('id')->get()
            ->map(fn($user) => ['id' => $user->id, 'name' => $user->person->f_name]);
    }

    public function fetchLocationLogs(): void
    {
        if (!$this->selectedUser) {
            $this->locationLogs = [];
            return;
        }

        $query = LocationLog::where('user_id', $this->selectedUser);

        if ($this->startDate) {
            $query->where('created_at', '>=', $this->startDate . ' 00:00:00');
        }
        if ($this->endDate) {
            $query->where('created_at', '<=', $this->endDate . ' 23:59:59');
        }

        $this->locationLogs = $query->orderBy('created_at')->get()
            ->map(function ($log) {
                return [
                    'lat' => $log->latitude,
                    'lng' => $log->longitude,
                    'timestamp' => $log->created_at->format('Y-m-d H:i:s'),
                ];
            })->toArray();
    }
};
?>


<div><style>
        .controls-panel {
            padding: 10px;
            background: rgba(255,255,255,0.8);
            border-radius: 10px;
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 9999;
            width: 250px;
        }
    </style>
    <x-header title="لاگ موقعیت کاربران" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container">

            <div wire:ignore>
                <livewire:maps.map/>
            </div>

            <div class="controls-panel">
                <div class="space-y-2">
                    <div>

                        <x-select wire:model.live="selectedUser" class="w-full" :options="$users" label="کاربر"/>

                    </div>

                    <div>
                        <label class="font-bold">از تاریخ:</label>
                        <input type="date" wire:model="startDate" class="w-full">
                    </div>

                    <div>
                        <label class="font-bold">تا تاریخ:</label>
                        <input type="date" wire:model="endDate" class="w-full">
                    </div>

                    <x-button wire:click="fetchLocationLogs" onClick="showMarkers()" class="btn btn-primary w-full mt-2" label="جستجو"/>
                </div>
            </div>

        </div>
    </x-card>
</div>
@script
<script>
    let locationMarkers = [];
    function showMarkers() {

        let loca = @js($locationLogs);
        console.log(loca);
        clearMarkers();
        addMarkers(loca);
    }



    function clearMarkers() {
        if (locationMarkers.length > 0) {
            locationMarkers.forEach(marker => map.removeLayer(marker));
            locationMarkers = [];
        }
    }

    function addMarkers(logs) {
        logs.forEach(log => {
            let marker = L.marker([log.lat, log.lng]).addTo(map);
            marker.bindPopup(`<b>تاریخ و زمان:</b><br>${log.timestamp}`);
            locationMarkers.push(marker);
        });

        if (logs.length > 0) {
            map.setView([logs[0].lat, logs[0].lng], 14);
        }
    }
</script>
@endscript
