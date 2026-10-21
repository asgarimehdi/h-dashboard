<?php

use App\Models\Region;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

return new class extends Component
{
    /**
     * The county geometry is deliberately NOT a public property (#1173).
     *
     * This page has no Livewire interaction at all (no wire:model/wire:click),
     * so a public `$regions` only duplicated the boundary geometry: once in
     * the dehydrated snapshot of every request and once in the `@script` block
     * that publishes it to the map. The geometry belongs to the map layer, so
     * it is loaded into this non-public holder and published exactly once.
     *
     * @var array<int, array{id: int, name: string|null, geojson: string|null}>|null
     */
    private ?array $regionsData = null;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->regionsData = $this->fetchRegions();
    }

    /**
     * The scoped county list with its boundary geometry.
     *
     * @return array<int, array{id: int, name: string|null, geojson: string|null}>
     */
    #[Computed]
    public function regions(): array
    {
        // The holder is not public state, so it is not dehydrated: a
        // re-hydrated component (a later Livewire request) loads it again.
        return $this->regionsData ??= $this->fetchRegions();
    }

    /**
     * @return array<int, array{id: int, name: string|null, geojson: string|null}>
     */
    private function fetchRegions(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $accessibleRegionIds = Unit::whereIn('id', $accessibleIds)
            ->whereNotNull('region_id')
            ->pluck('region_id');

        return Cache::remember('county:regions_with_boundaries:v' . Cache::get('maps_version', 0) . ':' . md5(implode(',', $accessibleRegionIds->all())), 300, function () use ($accessibleRegionIds) {
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
                @foreach ($this->regions as $region)
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

@assets
    {{-- Issue #1182: Leaflet core is page-specific now and ships on the HOST page
         — never inside `maps/map.blade.php`, whose own asset would arrive with the
         child component, after this page's Alpine `init()` had already asked the
         store for a map. `@assets`, not `@push('scripts')`: a pushed tag lands at
         the end of the body, and on `wire:navigate` Livewire morphs the body and
         runs Alpine BEFORE evaluating those scripts — measured `L is not defined`
         and a blank map (#028). Livewire injects `@assets` into <head> and
         evaluates new head assets before the navigation completes. --}}
    <script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
@endassets

@script
<script>
    var geojsonLayers = {};
    var allregions = {{ Js::from($this->regions) }};

    // Issue #028 — attach to the live map the store owns. Calling onReady()
    // (instead of waiting for a `window.map` a previous page may still own)
    // also removes any script-ordering race with maps.map.
    window.Alpine.store('map').onReady(function (map) {
        window.toggleGeoJson = function(regionId) {
            const region = allregions.find(r => r.id === regionId);
            if (!region || !region.geojson) {
                console.warn('No geojson found for region:', regionId);
                return;
            }

            if (geojsonLayers[regionId]) {
                map.removeLayer(geojsonLayers[regionId]);
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
                    }).addTo(map);

                    geojsonLayers[regionId] = newLayer;

                    // Zoom to layer bounds
                    map.fitBounds(newLayer.getBounds());
                } catch (e) {
                    console.error('Error parsing GeoJSON:', e);
                }
            }
        };
    });
</script>
@endscript