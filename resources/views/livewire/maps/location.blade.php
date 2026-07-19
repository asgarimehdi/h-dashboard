<?php

use App\Models\Unit;
use App\Models\User;
use App\Models\LocationLog;
use Carbon\Carbon;
use Livewire\Component;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    public $users = [];
    public $allUnitsTree = [];
    public $selectedUser = null;
    public $selectedUnitId = null;
    public $startDate;
    public $startTime = '00:00';
    public $endDate;
    public $endTime = '23:59';
    public $locationLogs = [];
    public $showHeatmap = false;
    public $liveMode = false;
    public $liveMinutes = 30;

    public function mount(): void
    {
        $this->users = User::orderBy('id')->get()
            ->map(fn($user) => ['id' => $user->id, 'name' => $user->person->f_name]);

        $units = Unit::with('unitType')->where('is_active', true)->orderBy('name')->get();
        $this->allUnitsTree = $units->map(fn($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'parent_id' => $u->parent_id,
            'unit_type_name' => $u->unitType?->name,
        ])->all();
    }

    private function parseJalaliDateTime(?string $date, string $time, bool $endOfMinute = false): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        try {
            $timeParts = explode(':', $time ?: '00:00');
            $hour = (int) ($timeParts[0] ?? 0);
            $minute = (int) ($timeParts[1] ?? 0);
            $second = $endOfMinute ? 59 : 0;

            return Jalalian::fromFormat('Y/m/d', $date)
                ->toCarbon()
                ->setTime($hour, $minute, $second);
        } catch (\Throwable) {
            return null;
        }
    }

    public function fetchLocationLogs(): void
    {
        if ($this->showHeatmap) {
            $this->fetchHeatmapData();

            return;
        }

        if (! $this->selectedUser) {
            $this->locationLogs = [];

            return;
        }

        $query = LocationLog::where('user_id', $this->selectedUser);

        if ($from = $this->parseJalaliDateTime($this->startDate, $this->startTime)) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $this->parseJalaliDateTime($this->endDate, $this->endTime, endOfMinute: true)) {
            $query->where('created_at', '<=', $to);
        }

        $this->locationLogs = $query->limit(20)->orderBy('created_at')->get()
            ->map(function ($log) {
                return [
                    'lat' => $log->latitude,
                    'lng' => $log->longitude,
                    'timestamp' => Jalalian::fromCarbon($log->created_at)->format('Y/m/d H:i:s'),
                ];
            })->toArray();

        $this->dispatch('showMapData', logs: $this->locationLogs, mode: 'markers');
    }

    public function fetchHeatmapData(): void
    {
        $query = LocationLog::with('user.person');

        if ($this->selectedUnitId) {
            $unitIds = Unit::descendantIds($this->selectedUnitId);
            $userIds = User::join('user_units', 'users.id', '=', 'user_units.user_id')
                ->whereIn('user_units.unit_id', $unitIds)
                ->pluck('users.id');
            $query->whereIn('user_id', $userIds);
        }

        if ($this->liveMode) {
            $query->where('created_at', '>=', now()->subMinutes($this->liveMinutes));
        } else {
            if ($from = $this->parseJalaliDateTime($this->startDate, $this->startTime)) {
                $query->where('created_at', '>=', $from);
            }

            if ($to = $this->parseJalaliDateTime($this->endDate, $this->endTime, endOfMinute: true)) {
                $query->where('created_at', '<=', $to);
            }
        }

        $logs = $query->latest()->limit(5000)->get(['user_id', 'latitude', 'longitude', 'created_at']);

        $data = $logs->map(fn($l) => [
            'lat' => $l->latitude,
            'lng' => $l->longitude,
            'userId' => $l->user_id,
            'userName' => $l->user?->person?->f_name ?? 'نامشخص',
            'time' => $l->created_at->toISOString(),
        ])->toArray();

        $this->dispatch('showMapData', logs: $data, mode: 'heatmap');
    }

    public function updatedShowHeatmap(): void
    {
        if ($this->showHeatmap) {
            $this->selectedUser = null;
            $this->fetchHeatmapData();
        } else {
            $this->locationLogs = [];
            $this->dispatch('showMapData', logs: [], mode: 'markers');
        }
    }
};
?>

<div>
    <x-header title="ردیابی لوکیشن کاربران" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container relative">
            <div wire:ignore>
                <livewire:maps.map/>
            </div>

            <div class="controls-panel">
                <div class="space-y-4">
                    {{-- View mode toggle --}}
                    <div class="flex gap-2">
                        <button wire:click="$set('showHeatmap', false)"
                            class="btn btn-sm flex-1 {{ ! $showHeatmap ? 'btn-primary' : 'btn-ghost' }}">
                            نشانگرها
                        </button>
                        <button wire:click="$set('showHeatmap', true)"
                            class="btn btn-sm flex-1 {{ $showHeatmap ? 'btn-primary' : 'btn-ghost' }}">
                            حرارتی
                        </button>
                    </div>

                    {{-- Heatmap controls --}}
                    @if ($showHeatmap)
                        {{-- Unit picker --}}
                        <div>
                            <label class="font-bold block mb-2">فیلتر واحد:</label>
                            @include('livewire.partials.unit-tree-picker', [
                                'units' => $allUnitsTree,
                                'model' => 'selectedUnitId',
                                'multiple' => false,
                                'alwaysOpen' => false,
                                'label' => null,
                            ])
                        </div>

                        {{-- Live mode toggle --}}
                        <div class="flex items-center gap-2">
                            <input type="checkbox" wire:model.live="liveMode" id="liveModeToggle" class="toggle toggle-success">
                            <label for="liveModeToggle" class="text-sm cursor-pointer">حضور آنی</label>
                        </div>

                        @if ($liveMode)
                            <div>
                                <label class="text-sm block mb-1">بازه حضور (دقیقه):</label>
                                <input type="number" wire:model.live="liveMinutes" min="5" max="120"
                                    class="input input-bordered input-sm w-full">
                            </div>
                        @endif
                    @endif

                    {{-- User picker (markers only) --}}
                    @if (! $showHeatmap)
                        <div>
                            <label class="font-bold block mb-2">انتخاب کاربر:</label>
                            <select wire:model.live="selectedUser" class="select select-bordered w-full">
                                <option value="">-- کاربر را انتخاب کنید --</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user['id'] }}">{{ $user['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label class="font-bold block mb-2">از تاریخ و ساعت:</label>
                        <div wire:ignore>
                            <input data-jdp id="location_start_date" placeholder="از تاریخ"
                                class="input input-bordered w-full mb-1 text-center cursor-pointer" readonly>
                        </div>
                        <input type="time" wire:model.live="startTime" class="input input-bordered w-full">
                    </div>

                    <div>
                        <label class="font-bold block mb-2">تا تاریخ و ساعت:</label>
                        <div wire:ignore>
                            <input data-jdp id="location_end_date" placeholder="تا تاریخ"
                                class="input input-bordered w-full mb-1 text-center cursor-pointer" readonly>
                        </div>
                        <input type="time" wire:model.live="endTime" class="input input-bordered w-full">
                    </div>

                    <x-button wire:click="fetchLocationLogs" class="btn btn-success w-full mt-2" label="دریافت"/>
                </div>
            </div>
        </div>
    </x-card>
</div>

@assets
<link rel="stylesheet" href="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.css">
<style>
    .controls-panel {
        padding: 15px;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 12px;
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 9999;
        width: 270px;
        box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.2);
        max-height: 80vh;
        overflow-y: auto;
    }
    .dark .controls-panel {
        background: rgba(30, 30, 30, 0.9);
    }
    @keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(1.5); }
    }
    .live-pulse {
        width: 12px;
        height: 12px;
        background: #22c55e;
        border-radius: 50%;
        animation: pulse-dot 1.5s ease-in-out infinite;
    }
</style>
@endassets

@script
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script>
    var heatLayer = null;
    var livePulseMarkers = [];

    function waitForMap(callback) {
        if (window.map && typeof window.map.getSize === 'function') {
            callback();
        } else {
            setTimeout(() => waitForMap(callback), 100);
        }
    }

    function clearMapData() {
        // Remove markers
        if (window.locationMarkers && window.locationMarkers.length > 0) {
            window.locationMarkers.forEach(marker => window.map.removeLayer(marker));
            window.locationMarkers = [];
        }

        // Remove polyline
        if (window.locationPolyline) {
            window.map.removeLayer(window.locationPolyline);
            window.locationPolyline = null;
        }

        // Remove heat layer
        if (heatLayer) {
            window.map.removeLayer(heatLayer);
            heatLayer = null;
        }

        // Remove live pulse markers
        livePulseMarkers.forEach(m => window.map.removeLayer(m));
        livePulseMarkers = [];
    }

    function addMarkersAndPolyline(logs) {
        var latlngs = [];

        logs.forEach((log, index) => {
            var latlng = [log.lat, log.lng];
            latlngs.push(latlng);

            var marker = L.marker(latlng).addTo(window.map);
            marker.bindPopup('<b>' + (index + 1) + '. زمان:</b><br>' + log.timestamp);
            window.locationMarkers.push(marker);
        });

        window.locationPolyline = L.polyline(latlngs, {
            color: 'blue',
            weight: 4,
            opacity: 0.7,
            smoothFactor: 1
        }).addTo(window.map);

        if (latlngs.length > 0) {
            var bounds = L.latLngBounds(latlngs);
            window.map.fitBounds(bounds, { padding: [50, 50] });
        }
    }

    function addHeatmap(data) {
        clearMapData();

        var points = data.map(function (d) {
            return [d.lat, d.lng, 1];
        });

        heatLayer = L.heatLayer(points, {
            radius: 25,
            blur: 15,
            maxZoom: 17,
            gradient: {
                0.2: 'blue',
                0.4: 'lime',
                0.6: 'yellow',
                0.8: 'orange',
                1.0: 'red'
            }
        }).addTo(window.map);

        if (points.length > 0) {
            var bounds = L.latLngBounds(points.map(function (p) { return [p[0], p[1]]; }));
            window.map.fitBounds(bounds, { padding: [50, 50] });
        }
    }

    function addLivePresence(data) {
        clearMapData();

        data.forEach(function (d) {
            var icon = L.divIcon({
                html: '<div class="live-pulse" title="' + (d.userName || 'نامشخص') + '"></div>',
                className: '',
                iconSize: [12, 12],
                iconAnchor: [6, 6]
            });

            var marker = L.marker([d.lat, d.lng], { icon: icon }).addTo(window.map);
            marker.bindPopup('<b>' + (d.userName || 'نامشخص') + '</b><br>' + new Date(d.time).toLocaleString('fa-IR'));
            livePulseMarkers.push(marker);
        });

        if (livePulseMarkers.length > 0) {
            var allLats = livePulseMarkers.map(function (m) { return m.getLatLng().lat; });
            var allLngs = livePulseMarkers.map(function (m) { return m.getLatLng().lng; });
            var bounds = L.latLngBounds(
                [Math.min.apply(null, allLats), Math.min.apply(null, allLngs)],
                [Math.max.apply(null, allLats), Math.max.apply(null, allLngs)]
            );
            window.map.fitBounds(bounds, { padding: [50, 50] });
        }
    }

    function showMapData(logs, mode) {
        clearMapData();
        if (!logs || logs.length === 0) return;

        if (mode === 'heatmap') {
            addHeatmap(logs);
        } else {
            addMarkersAndPolyline(logs);
        }
    }

    // Initialise map listener
    waitForMap(function () {
        window.locationMarkers = [];
        window.locationPolyline = null;

        // Handle initial data
        var initialLogs = {{ Js::from($locationLogs) }};
        if (initialLogs.length > 0) {
            addMarkersAndPolyline(initialLogs);
        }

        // Dispatch handler
        window.showMapData = showMapData;
    });

    // Listen for Livewire events
    Livewire.on('showMapData', function (data) {
        if (window.showMapData) {
            window.showMapData(data.logs, data.mode || 'markers');
        }
    });

    // Jalali date picker init
    function initLocationJdp() {
        if (typeof jalaliDatepicker === 'undefined') return;

        jalaliDatepicker.startWatch({
            time: false,
            hasSecond: false,
            format: 'YYYY/MM/DD',
            separatorChars: { date: '/', between: ' ', time: ':' },
        });

        var startInput = document.getElementById('location_start_date');
        var endInput = document.getElementById('location_end_date');

        if (startInput && !startInput.dataset.jdpBound) {
            startInput.dataset.jdpBound = '1';
            startInput.addEventListener('jdp:change', function (e) {
                $wire.set('startDate', e.target.value);
            });
        }
        if (endInput && !endInput.dataset.jdpBound) {
            endInput.dataset.jdpBound = '1';
            endInput.addEventListener('jdp:change', function (e) {
                $wire.set('endDate', e.target.value);
            });
        }
    }

    initLocationJdp();
    document.addEventListener('livewire:navigated', initLocationJdp);

    // Live presence mode: add pulsing markers
    function enableLiveMode(data) {
        addLivePresence(data);
    }
</script>
@endscript