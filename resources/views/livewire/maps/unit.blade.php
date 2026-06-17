<?php

use App\Models\Unit;
use Livewire\Component;

return new class extends Component
{
    public $units;

    public function mount(): void
    {
        $this->units = Unit::with('boundary')
            ->whereNotNull('boundary_id')
            ->get()
            ->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'geojson' => $unit->boundary?->geojson ?? null,
                ];
            })
            ->toArray();
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
                @foreach ($units as $unit)
                    <x-toggle
                        label="{{ $unit['name'] }}"
                        wire:key="unit-{{ $unit['id'] }}"
                        x-on:click="toggleGeoJson({{ $unit['id'] }})"
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
    var allUnits = {{ Js::from($units) }};

    window.toggleGeoJson = function(unitId) {
        if (!window.map) {
            console.error('Map not initialized');
            return;
        }
        
        const unit = allUnits.find(u => u.id === unitId);
        if (!unit || !unit.geojson) {
            console.warn('No geojson found for unit:', unitId);
            return;
        }

        if (geojsonLayers[unitId]) {
            window.map.removeLayer(geojsonLayers[unitId]);
            delete geojsonLayers[unitId];
        } else {
            try {
                let data = typeof unit.geojson === 'string' 
                    ? JSON.parse(unit.geojson) 
                    : unit.geojson;
                    
                let newLayer = L.geoJSON(data, {
                    style: {
                        color: "orange",
                        weight: 2,
                        opacity: 0.8,
                        fillOpacity: 0.1,
                    }
                }).addTo(window.map);
                
                geojsonLayers[unitId] = newLayer;
                
                // Zoom to layer bounds
                window.map.fitBounds(newLayer.getBounds());
            } catch (e) {
                console.error('Error parsing GeoJSON:', e);
            }
        }
    };
</script>
@endscript