<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\Region;
use Livewire\Component;
use Illuminate\Support\Facades\Cache;

return new class extends Component
{
    public bool $showHelpModal = false;

    public $units = [];

    public $regions = [];
    public $centerTypes = [];
    public $subTypes = [];

    public array $selectedRegions = [];
    public array $selectedCenterTypes = [];
    public array $selectedSubTypes = [];
    public string $search = '';

    private const SUB_TYPE_MAP = [
        'خانه بهداشت' => [9, 18],
        'پایگاه'      => [8, 10],
        'قمر'         => [12],
    ];

    public function mount(): void
    {
        $this->regions = Region::where('type', 'county')
            ->select('id', 'name')
            ->get()
            ->toArray();

        $this->centerTypes = UnitType::whereIn('id', [5, 6, 7])
            ->select('id', 'name')
            ->get()
            ->toArray();

        $this->subTypes = [
            ['name' => 'خانه بهداشت'],
            ['name' => 'پایگاه'],
            ['name' => 'قمر'],
        ];
    }

    public function updatedSelectedRegions(): void
    {
        $this->showSelectedCounties();
        $this->loadUnits();
    }

    private function showSelectedCounties(): void
    {
        if (empty($this->selectedRegions)) {
            $this->dispatch('county-boundaries-loaded', counties: []);
            return;
        }

        $cacheKey = 'county_boundaries_' . md5(implode(',', $this->selectedRegions));

        $counties = Cache::remember($cacheKey, 300, function () {
            return Region::whereIn('id', $this->selectedRegions)
                ->whereNotNull('boundary_id')
                ->with('boundary')
                ->get()
                ->map(fn($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'geojson' => $r->boundary?->geojson,
                ])
                ->filter(fn($r) => $r['geojson'])
                ->values()
                ->toArray();
        });

        $this->dispatch('county-boundaries-loaded', counties: $counties);
    }

    public function updatedSelectedSubTypes(): void
    {
        $this->loadUnits();
    }

    public function updatedSelectedCenterTypes(): void
    {
        $this->loadUnits();
    }

    public function loadUnits(): void
    {
        if (empty($this->selectedRegions)) {
            $this->units = [];
            $this->dispatch('units-updated', units: []);
            return;
        }

        $units = collect();

        // Centers (types 5, 6, 7) — filtered by selected center types
        $centers = collect();
        if (!empty($this->selectedCenterTypes)) {
            $centers = Unit::whereNotNull('boundary_id')
                ->whereIn('region_id', $this->selectedRegions)
                ->whereIn('unit_type_id', $this->selectedCenterTypes)
                ->select('id', 'name', 'unit_type_id')
                ->with('boundary:id,unit_id,geojson')
                ->get();
            $units = $units->merge($centers);
        }

        // Sub-types (خانه بهداشت, پایگاه, قمر) — only if their parent center type is selected
        if (!empty($this->selectedSubTypes) && !empty($this->selectedCenterTypes)) {
            $subTypeIds = $this->resolveSubTypeIds();

            // Get IDs of selected centers from the already-loaded collection (in-memory, no extra query)
            $parentIds = $centers->pluck('id');

            $subUnits = Unit::whereNotNull('boundary_id')
                ->whereIn('region_id', $this->selectedRegions)
                ->whereIn('unit_type_id', $subTypeIds)
                ->whereIn('parent_id', $parentIds)
                ->select('id', 'name', 'unit_type_id')
                ->with('boundary:id,unit_id,geojson')
                ->get();
            $units = $units->merge($subUnits);
        }

        $this->units = $units->map(fn($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'type_id' => $u->unit_type_id,
            'geojson' => $u->boundary?->geojson,
        ])->toArray();

        $this->dispatch('units-updated', units: $this->units);
    }

    private function resolveSubTypeIds(): array
    {
        $typeIds = [];
        foreach ($this->selectedSubTypes as $name) {
            $typeIds = array_merge($typeIds, self::SUB_TYPE_MAP[$name] ?? []);
        }
        return array_unique($typeIds);
    }
};
?>

<div>
    <x-header title="نقشه واحد ها" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="maps" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    {{-- Filter bar above the map --}}
    <x-card shadow class="mb-4 p-3">
        <div class="flex flex-wrap items-center gap-4">
            {{-- Counties --}}
            <div class="flex flex-wrap items-center gap-1">
                <label class="font-bold text-sm ml-1">شهرستان:</label>
                @foreach ($regions as $region)
                    <x-toggle
                        right
                        wire:model.live="selectedRegions"
                        value="{{ $region['id'] }}"
                        label="{{ $region['name'] }}"
                    />
                @endforeach
            </div>

            @if (!empty($selectedRegions))
                <hr class="border-base-300" />

                {{-- Center types --}}
                <div class="flex flex-wrap items-center gap-1">
                    <label class="font-bold text-sm ml-1">مرکز:</label>
                    @foreach ($centerTypes as $type)
                        <x-toggle
                            right
                            wire:model.live="selectedCenterTypes"
                            value="{{ $type['id'] }}"
                            label="{{ $type['name'] }}"
                        />
                    @endforeach
                </div>

                <hr class="border-base-300" />

                {{-- Sub-types --}}
                <div class="flex flex-wrap items-center gap-1">
                    <label class="font-bold text-sm ml-1">نوع مرکز:</label>
                    @foreach ($subTypes as $type)
                        <x-toggle
                            right
                            wire:model.live="selectedSubTypes"
                            value="{{ $type['name'] }}"
                            label="{{ $type['name'] }}"
                        />
                    @endforeach
                </div>
            @endif
        </div>
    </x-card>

    {{-- Map + right-side unit list --}}
    <x-card shadow class="p-0">
        <div class="relative">
            <livewire:maps.map/>

            <div class="unit-menu bg-base-100/60 rounded-l-box" id="unitMenu">
                {{-- Search --}}
                <div class="mb-3">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="جستجو..."
                        class="input input-bordered input-sm w-full"
                    />
                </div>

                {{-- Unit list --}}
                @foreach ($units as $unit)
                    <x-toggle
                        right
                        label="{{ $unit['name'] }}"
                        wire:key="unit-{{ $unit['id'] }}"
                        x-on:change="$event.target.checked ? toggleGeoJsonOn({{ $unit['id'] }}) : toggleGeoJsonOff({{ $unit['id'] }})"
                    />
                @endforeach

                @if (!empty($selectedRegions) && (!empty($selectedCenterTypes) || !empty($selectedSubTypes)) && empty($units))
                    <p class="text-sm text-base-content/50 text-center">واحدی یافت نشد</p>
                @endif
            </div>
        </div>
    </x-card>
</div>

@assets
<style>
    .unit-menu {
        padding: 10px;
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 1000;
        width: 270px;
        max-height: 80vh;
        overflow-y: auto;
    }
</style>
@endassets

@script
<script>
    var geojsonLayers = {};
    var allUnits = {{ Js::from($units) }};
    var countyLayers = {};
    var activeToggles = {};

    function waitForMap(callback) {
        var tries = 0;
        function check() {
            if (window.map && typeof window.map.getSize === 'function') {
                callback();
            } else if (++tries > 50) {
                console.error('Map not ready within 10s');
            } else {
                setTimeout(check, 200);
            }
        }
        check();
    }

    function clearAllLayers() {
        Object.keys(geojsonLayers).forEach(function(id) {
            window.map.removeLayer(geojsonLayers[id]);
            delete geojsonLayers[id];
        });
    }

    function clearCountyLayers() {
        Object.keys(countyLayers).forEach(function(id) {
            window.map.removeLayer(countyLayers[id]);
            delete countyLayers[id];
        });
    }

    function showCountyBoundaries(counties) {
        clearCountyLayers();
        counties.forEach(function(c) {
            if (c.geojson && !countyLayers[c.id]) {
                try {
                    let data = typeof c.geojson === 'string' ? JSON.parse(c.geojson) : c.geojson;
                    countyLayers[c.id] = L.geoJSON(data, {
                        style: { color: "#3b82f6", weight: 2, opacity: 0.7, fillOpacity: 0.05 }
                    }).addTo(window.map);
                } catch (e) {
                    console.error('Error adding county boundary:', e);
                }
            }
        });
    }

    function showUnits(units) {
        units.forEach(function(unit) {
            if (unit.geojson && !geojsonLayers[unit.id]) {
                try {
                    let data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
                    geojsonLayers[unit.id] = L.geoJSON(data, {
                        style: { color: "orange", weight: 2, opacity: 0.8, fillOpacity: 0.1 }
                    }).addTo(window.map);
                    activeToggles[unit.id] = true;
                } catch (e) {
                    console.error('Error adding GeoJSON:', e);
                }
            }
        });
    }

    window.toggleGeoJsonOn = function(unitId) {
        if (!window.map) return;
        const unit = allUnits.find(u => u.id === unitId);
        if (!unit || !unit.geojson || geojsonLayers[unitId]) return;
        activeToggles[unitId] = true;
        try {
            let data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
            geojsonLayers[unitId] = L.geoJSON(data, {
                style: { color: "orange", weight: 2, opacity: 0.8, fillOpacity: 0.1 }
            }).addTo(window.map);
            if (geojsonLayers[unitId].getBounds) {
                map.fitBounds(geojsonLayers[unitId].getBounds().pad(0.1));
            }
        } catch (e) {
            console.error('Error parsing GeoJSON:', e);
        }
    };

    window.toggleGeoJsonOff = function(unitId) {
        delete activeToggles[unitId];
        if (geojsonLayers[unitId]) {
            window.map.removeLayer(geojsonLayers[unitId]);
            delete geojsonLayers[unitId];
        }
    };

    function syncToggleStates() {
        document.querySelectorAll('[wire\\:key^="unit-"]').forEach(function(el) {
            var input = el.querySelector('input[type="checkbox"]');
            if (!input) return;
            var key = el.getAttribute('wire:key');
            var unitId = parseInt(key.replace('unit-', ''));
            if (activeToggles[unitId]) {
                input.checked = true;
            }
        });
    }

    waitForMap(function() {
        Livewire.on('county-boundaries-loaded', function({ counties }) {
            showCountyBoundaries(counties);
        });

        Livewire.on('units-updated', function({ units }) {
            clearAllLayers();
            allUnits = units;
            showUnits(allUnits);
            requestAnimationFrame(syncToggleStates);
        });
    });
</script>
@endscript