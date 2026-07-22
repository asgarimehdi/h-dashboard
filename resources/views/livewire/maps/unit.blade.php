<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\Region;
use Livewire\Component;

return new class extends Component
{
    public $units = [];

    public $regions = [];
    public $types = [];

    public array $selectedRegions = [];
    public array $selectedTypes = [5, 6, 7];
    public string $search = '';

    public function mount(): void
    {
        $this->regions = Region::where('type', 'county')
            ->select('id', 'name')
            ->get()
            ->toArray();

        $this->types = UnitType::whereIn('id', [5, 6, 7])
            ->select('id', 'name')
            ->get()
            ->toArray();

        $this->fetchUnits();
    }

    public function fetchUnits(): void
    {
        if (empty($this->selectedRegions)) {
            $this->units = [];
            $this->dispatch('units-updated', units: []);
            return;
        }

        $query = Unit::with('boundary')
            ->whereNotNull('boundary_id')
            ->whereIn('region_id', $this->selectedRegions);

        if ($this->selectedTypes) {
            $query->whereIn('unit_type_id', $this->selectedTypes);
        }

        if ($this->search !== '') {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        $this->units = $query->get()
            ->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'geojson' => $unit->boundary?->geojson ?? null,
                ];
            })
            ->toArray();

        $this->dispatch('units-updated', units: $this->units);
    }

    public function updatedSelectedRegions(): void
    {
        $this->fetchUnits();
    }

    public function updatedSelectedTypes(): void
    {
        $this->fetchUnits();
    }

    public function updatedSearch(): void
    {
        $this->fetchUnits();
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
                <div class="mb-4">
                    <label class="font-bold block mb-2">جستجو</label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="جستجوی نام..."
                        class="input input-bordered w-full"
                    />
                </div>

                {{-- County toggles --}}
                <div class="mb-4">
                    <label class="font-bold block mb-2">شهرستان</label>
                    @foreach ($regions as $region)
                        <x-toggle
                            right
                            wire:model.live="selectedRegions"
                            value="{{ $region['id'] }}"
                            label="{{ $region['name'] }}"
                        />
                    @endforeach
                </div>

                {{-- Type toggles --}}
                <div class="mb-4">
                    <label class="font-bold block mb-2">نوع</label>
                    @foreach ($types as $type)
                        <x-toggle
                            right
                            wire:model.live="selectedTypes"
                            value="{{ $type['id'] }}"
                            label="{{ $type['name'] }}"
                        />
                    @endforeach
                </div>

                <hr class="border-base-300 my-4" />

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
