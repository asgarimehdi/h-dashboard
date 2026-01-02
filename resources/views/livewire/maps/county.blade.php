<?php

use App\Models\Region;
use Livewire\Volt\Component;

new class extends Component
{
    public $regions;

    public function mount()
    {
        $this->regions = Region::with('boundary')
            ->whereNotNull('boundary_id')
            ->get()
            ->map(function ($region) {
                $region->geojson = $region->boundary?->geojson ?? null;
                return [
                    'id' => $region->id,
                    'name' => $region->name,
                    'geojson' => $region->geojson,
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
    <x-header title="تقسیم‌بندی شهرستان" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container">
            <livewire:maps.map />

            <div class="unit-menu bg-base-100/60 rounded-l-box" id="unitMenu">
                @foreach ($regions as $region)
                    <x-toggle
                        label="{{ $region['name'] }}"
                        onclick="toggleGeoJson({{ $region['id'] }})"
                    ></x-toggle>
                @endforeach

            </div>
        </div>
    </x-card>
</div>

<script>
    var geojsonLayers = {};
    var allregions = @json($regions);

    function toggleGeoJson(regionId) {
        const region = allregions.find(r => r.id === regionId);
        if (!region || !region.geojson) {
            console.warn('No geojson found for region:', regionId);
            return;
        }

        if (geojsonLayers[regionId]) {
            map.removeLayer(geojsonLayers[regionId]);
            delete geojsonLayers[regionId];
        } else {
            let data = JSON.parse(region.geojson);
            let newLayer = L.geoJSON(data, {
                style: {
                    color: "orange",
                    weight: 2,
                    opacity: 0.8,
                    fillOpacity: 0.1,
                }
            }).addTo(map);
            geojsonLayers[regionId] = newLayer;
        }
    }
</script>

