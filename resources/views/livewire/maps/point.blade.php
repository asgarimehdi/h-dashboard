<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\Region;
use App\Services\AccessService;
use Livewire\Component;
use Illuminate\Support\Facades\Cache;

return new class extends Component
{
    public $location = [];

    public $types = [];

    public $regions = [];

    public array $selectedRegions = [];

    public array $selectedTypes = [];

    public function mount(): void
    {
        $excludedRegionIds = [1];
        $excludedTypeIds   = [1, 2, 3];

        $v = Cache::get('maps_version', 0);

        $this->regions = Cache::remember('point_map:regions:v' . $v, 300, function () use ($excludedRegionIds) {
            return Region::whereNotIn('id', $excludedRegionIds)
                ->select('id', 'name')
                ->get()
                ->toArray();
        });

        $this->types = Cache::remember('point_map:types:v' . $v, 300, function () use ($excludedTypeIds) {
            return UnitType::whereNotIn('id', $excludedTypeIds)
                ->select('id', 'name')
                ->get()
                ->toArray();
        });

        $this->fetchLocation();
    }

    public function fetchLocation(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = Unit::query()
            ->whereIn('id', $accessibleIds)  // Organizational Scope: only accessible units + descendants
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        if ($this->selectedRegions) {
            $query->whereIn('region_id', $this->selectedRegions);
        }

        if ($this->selectedTypes) {
            $query->whereIn('unit_type_id', $this->selectedTypes);
        }

        $baseUnits = $query
            ->limit(2000)
            ->select([
                'id',
                'name',
                'lat',
                'lng',
                'unit_type_id',
                'parent_id',
            ])
            ->get();

        // Include ancestor units so parent markers exist for connection lines (single JOIN instead of 2 queries)
        $baseIds = $baseUnits->pluck('id')->toArray();
        $allIds = array_unique(array_merge($baseIds, Unit::ancestorIds($baseIds)->toArray()));

        $this->location = Unit::whereIn('id', $allIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->select(['id', 'name', 'lat', 'lng', 'unit_type_id', 'parent_id'])
            ->get()
            ->toArray();

        $this->dispatch('locations-updated', locations: $this->location);
    }

    public function updatedSelectedRegions(): void
    {
        $this->fetchLocation();
    }

    public function updatedSelectedTypes(): void
    {
        $this->fetchLocation();
    }
};
?>

<div>
    <x-header title="نقاط لوکیشن" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="relative">
            <div wire:ignore>
                <livewire:maps.map/>
            </div>

            <div class="controls-panel space-y-4">
                <div>
                    <label class="font-bold block mb-2">انتخاب شهرستان</label>
                    @foreach ($regions as $region)
                        <x-toggle
                            wire:model.live="selectedRegions"
                            value="{{ $region['id'] }}"
                            label="{{ $region['name'] }}"
                        />
                    @endforeach
                </div>

                <div>
                    <label class="font-bold block mb-2">انتخاب نوع</label>
                    @foreach ($types as $type)
                        <x-toggle
                            wire:model.live="selectedTypes"
                            value="{{ $type['id'] }}"
                            label="{{ $type['name'] }}"
                        />
                    @endforeach
                </div>
            </div>
        </div>
    </x-card>
</div>

@assets
<style>
    .controls-panel {
        padding: 15px;
        background: rgba(255, 255, 255, .6);
        border-radius: 12px;
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 9999;
        width: 270px;
        box-shadow: 0 0 10px rgba(0, 0, 0, .2);
        max-height: 70vh;
        overflow-y: auto;
    }
    .dark .controls-panel {
        filter: invert(100%) hue-rotate(180deg);
    }
</style>
@endassets

@script
<script>
    const typeIcons = {
        4: '/icons/network.svg',
        5: '/icons/urban-health.svg',
        6: '/icons/urban-rural.svg',
        7: '/icons/rural-health.svg',
        8: '/icons/attached-base.svg',
        9: '/icons/health-house.svg',
        10: '/icons/base.svg',
        11: '/icons/block.svg',
        12: '/icons/satellite.svg',
        13: '/icons/rabies.svg',
        14: '/icons/dental.svg',
        15: '/icons/lab.svg',
        16: '/icons/school.svg',
        17: '/icons/emergency.svg',
        18: '/icons/worker-house.svg',
        19: '/icons/hospital.svg',
    };

    const defaultIcon = '/icons/default.svg';

    // Perf: one L.icon per type (was: a fresh L.icon for every marker).
    const iconCache = new Map();
    function getIcon(typeId) {
        let icon = iconCache.get(typeId);
        if (!icon) {
            icon = L.icon({
                iconUrl: typeIcons[typeId] ?? defaultIcon,
                iconSize: [32, 32],
                iconAnchor: [16, 32],
                popupAnchor: [0, -32],
            });
            iconCache.set(typeId, icon);
        }
        return icon;
    }

    const lineColors = ['#14b8a6', '#3b82f6', '#f97316', '#a855f7', '#ef4444'];

    // Perf: single canvas renderer for all connection lines (was: one SVG path per line).
    let lineRenderer = null;

    function renderMarkers(markersLayer, linesLayer, locations) {
        markersLayer.clearLayers();
        linesLayer.clearLayers();

        if (!lineRenderer) lineRenderer = L.canvas({ padding: 0.5 });

        // Perf: id → location Map + memoised depth (was: Array.find inside a
        // nested loop plus a full re-scan per node — O(N²)/O(N·depth)).
        const byId = new Map(locations.map(l => [l.id, l]));
        const depthCache = new Map();
        const depthOf = (id) => {
            if (depthCache.has(id)) return depthCache.get(id);
            const node = byId.get(id);
            if (!node || !node.parent_id) { depthCache.set(id, 0); return 0; }
            depthCache.set(id, 0); // placeholder — also breaks parent cycles
            const depth = depthOf(node.parent_id) + 1;
            depthCache.set(id, depth);
            return depth;
        };

        locations.forEach(loc => {
            if (loc.parent_id && loc.lat && loc.lng) {
                const parent = byId.get(loc.parent_id);
                if (parent && parent.lat && parent.lng) {
                    const color = lineColors[Math.min(depthOf(parent.id), lineColors.length - 1)];
                    L.polyline(
                        [[loc.lat, loc.lng], [parent.lat, parent.lng]],
                        { color, weight: 2, opacity: 0.7, dashArray: '6 4', renderer: lineRenderer }
                    ).addTo(linesLayer);
                }
            }
        });

        locations.forEach(loc => {
            L.marker(
                [loc.lat, loc.lng],
                { icon: getIcon(loc.unit_type_id) }
            )
                .bindPopup(loc.name)
                .addTo(markersLayer);
        });
    }

    // Issue #028 — take a LIVE map from the store instead of waiting for a
    // `window.map` that a previous page may still own. onReady() removes the
    // script-ordering race with maps.map entirely.
    window.Alpine.store('map').onReady(function (map) {
        const markersLayer = L.layerGroup().addTo(map);
        const linesLayer = L.layerGroup().addTo(map);

        // Render initial locations
        renderMarkers(markersLayer, linesLayer, {{ Js::from($location) }});

        // Listen for future updates from Livewire
        Livewire.on('locations-updated', ({ locations }) => {
            renderMarkers(markersLayer, linesLayer, locations);
        });
    });
</script>
@endscript