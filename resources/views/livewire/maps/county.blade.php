<?php

use App\Models\Region;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

return new class extends Component
{
    public $regions;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        
        $accessibleRegionIds = Unit::whereIn('id', $accessibleIds)
            ->whereNotNull('region_id')
            ->pluck('region_id');

$this->regions = Cache::remember('county:regions_with_boundaries:v' . Cache::get('maps_version', 0) . ':' . md5(implode(',', $accessibleRegionIds->all())), 300, function () use ($accessibleRegionIds) {
            return Region::query()
                ->whereNotNull('boundary_id')
                ->whereIn('regions.id', $accessibleRegionIds)
                ->select([
                    'regions.id',
                    'regions.name',
                    DB::raw('ST_AsGeoJSON(boundaries.boundary) as geojson'),
                ])
                ->join('boundaries', 'regions.boundary_id', '=', 'boundaries.id')
                ->get()
                ->map(fn($r) => [
                    'id'      => $r->id,
                    'name'    => $r->name,
                    'geojson' => $r->geojson,
                ])
                ->toArray();
        });
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
        <div class="relative">
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