<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\Region;
use Livewire\Component;

return new class extends Component
{
    public $units = [];
    public $centers = [];
    public $healthHouses = [];

    public $regions = [];
    public $centerTypes = [];

    public array $selectedRegions = [];
    public array $selectedTypes = [5, 6, 7];
    public array $selectedCenters = [];
    public string $search = '';

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

        $this->fetchCenters();
    }

    public function fetchCenters(): void
    {
        if (empty($this->selectedRegions)) {
            $this->centers = [];
            $this->healthHouses = [];
            $this->units = [];
            $this->selectedCenters = [];
            $this->dispatch('units-updated', units: []);
            return;
        }

        $this->centers = Unit::whereNotNull('boundary_id')
            ->whereIn('region_id', $this->selectedRegions)
            ->whereIn('unit_type_id', $this->selectedTypes)
            ->select('id', 'name')
            ->get()
            ->toArray();

        $this->selectedCenters = array_filter(
            $this->selectedCenters,
            fn($id) => collect($this->centers)->contains('id', $id)
        );

        $this->fetchHealthHouses();
    }

    public function fetchHealthHouses(): void
    {
        if (empty($this->selectedCenters)) {
            $this->healthHouses = [];
            $this->units = collect($this->centers)
                ->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'geojson' => null,
                ])
                ->toArray();
            $this->loadBoundaries();
            return;
        }

        $this->healthHouses = Unit::where('unit_type_id', 9)
            ->whereIn('parent_id', $this->selectedCenters)
            ->select('id', 'name', 'parent_id')
            ->get()
            ->toArray();

        $centerItems = collect($this->centers)
            ->filter(fn($c) => in_array($c['id'], $this->selectedCenters))
            ->map(fn($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'geojson' => null,
            ])
            ->toArray();

        $houseItems = collect($this->healthHouses)
            ->map(fn($h) => [
                'id' => $h['id'],
                'name' => $h['name'],
                'geojson' => null,
            ])
            ->toArray();

        $this->units = array_merge($centerItems, $houseItems);
        $this->loadBoundaries();
    }

    private function loadBoundaries(): void
    {
        if (empty($this->units)) {
            $this->dispatch('units-updated', units: []);
            return;
        }

        $ids = array_column($this->units, 'id');
        $boundaries = Unit::whereIn('id', $ids)
            ->whereNotNull('boundary_id')
            ->with('boundary')
            ->get()
            ->pluck('boundary.geojson', 'id')
            ->toArray();

        $this->units = array_map(function ($unit) use ($boundaries) {
            $unit['geojson'] = $boundaries[$unit['id']] ?? null;
            return $unit;
        }, $this->units);

        $this->dispatch('units-updated', units: $this->units);
    }

    public function updatedSelectedRegions(): void
    {
        $this->fetchCenters();
    }

    public function updatedSelectedTypes(): void
    {
        $this->fetchCenters();
    }

    public function updatedSelectedCenters(): void
    {
        $this->fetchHealthHouses();
    }

    public function updatedSearch(): void
    {
        $this->fetchCenters();
    }
};
?>

<div>
    <x-header title="نقشه واحد ها" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container">
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

                {{-- Step 1: County --}}
                <div class="mb-3">
                    <label class="font-bold text-sm block mb-1">۱. شهرستان</label>
                    @foreach ($regions as $region)
                        <x-toggle
                            right
                            wire:model.live="selectedRegions"
                            value="{{ $region['id'] }}"
                            label="{{ $region['name'] }}"
                        />
                    @endforeach
                </div>

                {{-- Step 2: Center type --}}
                @if (!empty($selectedRegions))
                    <div class="mb-3">
                        <label class="font-bold text-sm block mb-1">۲. نوع مرکز</label>
                        @foreach ($centerTypes as $type)
                            <x-toggle
                                right
                                wire:model.live="selectedTypes"
                                value="{{ $type['id'] }}"
                                label="{{ $type['name'] }}"
                            />
                        @endforeach
                    </div>
                @endif

                {{-- Step 3: Select centers --}}
                @if (!empty($centers))
                    <div class="mb-3">
                        <label class="font-bold text-sm block mb-1">۳. مرکز</label>
                        @foreach ($centers as $center)
                            <x-toggle
                                right
                                wire:model.live="selectedCenters"
                                value="{{ $center['id'] }}"
                                label="{{ $center['name'] }}"
                            />
                        @endforeach
                    </div>
                @endif

                <hr class="border-base-300 my-3" />

                {{-- Unit list --}}
                @foreach ($units as $unit)
                    <x-toggle
                        right
                        label="{{ $unit['name'] }}"
                        wire:key="unit-{{ $unit['id'] }}"
                        x-on:change="$event.target.checked ? toggleGeoJsonOn({{ $unit['id'] }}) : toggleGeoJsonOff({{ $unit['id'] }})"
                    />
                @endforeach
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

    window.toggleGeoJsonOn = function(unitId) {
        if (!window.map) return;

        const unit = allUnits.find(u => u.id === unitId);
        if (!unit || !unit.geojson || geojsonLayers[unitId]) return;

        try {
            let data = typeof unit.geojson === 'string'
                ? JSON.parse(unit.geojson)
                : unit.geojson;

            geojsonLayers[unitId] = L.geoJSON(data, {
                style: {
                    color: "orange",
                    weight: 2,
                    opacity: 0.8,
                    fillOpacity: 0.1,
                }
            }).addTo(window.map);
        } catch (e) {
            console.error('Error parsing GeoJSON:', e);
        }
    };

    window.toggleGeoJsonOff = function(unitId) {
        if (geojsonLayers[unitId]) {
            window.map.removeLayer(geojsonLayers[unitId]);
            delete geojsonLayers[unitId];
        }
    };

    waitForMap(function() {
        Livewire.on('units-updated', ({ units }) => {
            clearAllLayers();
            allUnits = units;
        });
    });
</script>
@endscript
