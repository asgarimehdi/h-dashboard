<?php

use App\Models\Unit;
use Livewire\Volt\Component;

new class extends Component
{
    public $units;

    public function mount()
    {
        $this->units = Unit::with('boundary')
            ->whereNotNull('boundary_id')
            ->get()
            ->map(function ($unit) {
                $unit->geojson = $unit->boundary?->geojson ?? null;
                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'geojson' => $unit->geojson,
                ];
            });


    }
};

?>


<style>
    .unit-menu {
        padding: 5px;
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 1000;
    }
</style>

<div>
    <x-header title="نقشه واحد ها" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container">
            <livewire:maps.map />

            <div class="unit-menu bg-base-100/60 rounded-l-box" id="unitMenu">
                @foreach ($units as $unit)
                    <x-toggle
                        label="{{ $unit['name'] }}"
                        onclick="toggleGeoJson({{ $unit['id'] }})"
                    ></x-toggle>
                @endforeach

            </div>
        </div>
    </x-card>
</div>

<script>
    var geojsonLayers = {};
    var allUnits = @json($units);

    function toggleGeoJson(unitId) {
        const unit = allUnits.find(u => u.id === unitId);
        if (!unit || !unit.geojson) {
            console.warn('No geojson found for unit:', unitId);
            return;
        }

        if (geojsonLayers[unitId]) {
            map.removeLayer(geojsonLayers[unitId]);
            delete geojsonLayers[unitId];
        } else {
            let data = JSON.parse(unit.geojson);
            let newLayer = L.geoJSON(data, {
                style: {
                    color: "orange",
                    weight: 2,
                    opacity: 0.8,
                    fillOpacity: 0.1,
                }
            }).addTo(map);
            geojsonLayers[unitId] = newLayer;
        }
    }
</script>

