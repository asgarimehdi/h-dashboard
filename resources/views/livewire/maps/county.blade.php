<?php

use App\Models\Region;
use Livewire\Component;

return new class extends Component
{
    public $regions;

    public function mount(): void
    {
        $this->regions = Region::with('boundary')
            ->whereNotNull('boundary_id')
            ->get()
            ->map(function ($region) {
                return [
                    'id' => $region->id,
                    'name' => $region->name,
                    'geojson' => $region->boundary?->geojson ?? null,
                ];
            })
            ->toArray();
    }
};
?>

<div>
    <x-header title="تقسیم‌بندی شهرستان" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container">
            <livewire:maps.map/>

            <div class="unit-menu bg-base-100/60 rounded-l-box" id="unitMenu">
                @foreach ($regions as $region)
                    <x-toggle
                        label="{{ $region['name'] }}"
                        wire:key="region-{{ $region['id'] }}"
                        x-on:click="toggleGeoJson({{ $region['id'] }})"
                    />
                @endforeach
            </div>
        </div>
    </x-card>
</div>

@assets
<style>
    .unit-menu {
        padding: 5px;
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 1000;
    }
</style>
@endassets

@script
<script>
    var geojsonLayers = {};
    var allregions = {{ Js::from($regions) }};

    window.toggleGeoJson = function(regionId) {
        if (!window.map) {
            console.error('Map not initialized');
            return;
        }
        
        const region = allregions.find(r => r.id === regionId);
        if (!region || !region.geojson) {
            console.warn('No geojson found for region:', regionId);
            return;
        }

        if (geojsonLayers[regionId]) {
            window.map.removeLayer(geojsonLayers[regionId]);
            delete geojsonLayers[regionId];
        } else {
            try {
                let data = typeof region.geojson === 'string' 
                    ? JSON.parse(region.geojson) 
                    : region.geojson;
                    
                let newLayer = L.geoJSON(data, {
                    style: {
                        color: "orange",
                        weight: 2,
                        opacity: 0.8,
                        fillOpacity: 0.1,
                    }
                }).addTo(window.map);
                
                geojsonLayers[regionId] = newLayer;
                
                // Zoom to layer bounds
                window.map.fitBounds(newLayer.getBounds());
            } catch (e) {
                console.error('Error parsing GeoJSON:', e);
            }
        }
    };
</script>
@endscript