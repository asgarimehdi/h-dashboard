<?php

use App\Models\User;
use App\Models\LocationLog;
use Livewire\Volt\Component;

new class extends Component
{
    public $users;
    public $selectedUser = null;
    public $startDate;
    public $startTime = '00:00';
    public $endDate;
    public $endTime = '23:59';
    public $locationLogs = [];

    public function mount()
    {
        $this->users = User::orderBy('id')->get()
            ->map(fn($user) => ['id' => $user->id, 'name' => $user->person->f_name]);

    }

    public function fetchLocationLogs()
    {
        if (!$this->selectedUser) {
            $this->locationLogs = [];
            return;
        }

        $query = LocationLog::where('user_id', $this->selectedUser);

        if ($this->startDate) {
            $query->where('created_at', '>=', "{$this->startDate} {$this->startTime}:00");
        }

        if ($this->endDate) {
            $query->where('created_at', '<=', "{$this->endDate} {$this->endTime}:59");
        }

        $this->locationLogs = $query->limit(20)->orderBy('created_at')->get()
            ->map(function ($log) {
                return [
                    'lat' => $log->latitude,
                    'lng' => $log->longitude,
                    'timestamp' => $log->created_at->format('Y-m-d H:i:s'),
                ];
            })->toArray();
        $this->js("showMapData(" . json_encode($this->locationLogs) . ")");
    }
};
?>


<div>
    <style>
        .controls-panel {
            padding: 15px;
            background: rgba(255,255,255,0.9);
            border-radius: 12px;
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 9999;
            width: 270px;
            box-shadow: 0px 0px 10px rgba(0,0,0,0.2);
        }
    </style>
    <x-header title="ردیابی لوکیشن کاربران" separator progress-indicator>
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
                <div class="space-y-4">
                    <div>
                        <label class="font-bold">انتخاب کاربر:</label>
                        <select wire:model.live="selectedUser" class="w-full">
                            <option value="">-- کاربر را انتخاب کنید --</option>
                            @foreach ($users as $user)
                                <option value="{{ $user['id'] }}">{{ $user['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="font-bold">از تاریخ و ساعت:</label>
                        <input type="date" wire:model="startDate" class="w-full mb-1">
                        <input type="time" wire:model="startTime" class="w-full">
                    </div>

                    <div>
                        <label class="font-bold">تا تاریخ و ساعت:</label>
                        <input type="date" wire:model="endDate" class="w-full mb-1">
                        <input type="time" wire:model="endTime" class="w-full">
                    </div>

                    <x-button wire:click="fetchLocationLogs" class="btn btn-success w-full mt-2" label="دریافت"/>

                </div>
            </div>

        </div>
    </x-card>
</div>
@script
<script>
    let locationMarkers = [];
    let locationPolyline = null;


    window.showMapData=function (localog) {
        {{--let localog=@js($this->locationLogs);--}}
        clearMapData();
        if (localog.length > 0) {
            addMarkersAndPolyline(localog);
        }
        // console.log(localog)
    }




    function clearMapData() {
        // Remove markers
        if (locationMarkers.length > 0) {
            locationMarkers.forEach(marker => map.removeLayer(marker));
            locationMarkers = [];
        }

        // Remove polyline
        if (locationPolyline) {
            map.removeLayer(locationPolyline);
            locationPolyline = null;
        }
    }

    function addMarkersAndPolyline(logs) {
        let latlngs = [];

        logs.forEach((log, index) => {
            const latlng = [log.lat, log.lng];
            latlngs.push(latlng);

            let marker = L.marker(latlng).addTo(map);
            marker.bindPopup(`<b>${index + 1}. زمان:</b><br>${log.timestamp}`);
            locationMarkers.push(marker);
        });

        locationPolyline = L.polyline(latlngs, {
            color: 'blue',
            weight: 4,
            opacity: 0.7,
            smoothFactor: 1
        }).addTo(map);

        if (latlngs.length > 0) {
            const bounds = L.latLngBounds(latlngs);
            map.fitBounds(bounds, { padding: [50, 50] });
        }
    }
</script>
@endscript
