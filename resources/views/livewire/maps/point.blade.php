<?php

use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitType;
use App\Services\AccessService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

return new class extends Component
{
    public $location = [];

    public $types = [];

    public $regions = [];

    public function mount(): void
    {
        $excludedRegionIds = [1];
        $excludedTypeIds = [1, 2, 3];

        $v = Cache::get('maps_version', 0);

        $this->regions = Cache::remember('point_map:regions:v'.$v, 300, function () use ($excludedRegionIds) {
            return Region::whereNotIn('id', $excludedRegionIds)
                ->select('id', 'name')
                ->get()
                ->toArray();
        });

        $this->types = Cache::remember('point_map:types:v'.$v, 300, function () use ($excludedTypeIds) {
            return UnitType::whereNotIn('id', $excludedTypeIds)
                ->select('id', 'name')
                ->get()
                ->toArray();
        });

        $this->fetchLocation();
    }

    /**
     * Load every accessible located unit once. Region/type filtering happens
     * client-side from this payload (filtering used to round-trip through
     * Livewire and re-send + re-render the whole set on every toggle —
     * ~2s per switch, issue #776).
     */
    public function fetchLocation(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $baseUnits = Unit::query()
            ->whereIn('id', $accessibleIds)  // Organizational Scope: only accessible units + descendants
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->limit(2000)
            ->select([
                'id',
                'name',
                'lat',
                'lng',
                'unit_type_id',
                'parent_id',
                'region_id',
            ])
            ->get();

        // Include ancestor units so parent markers exist for connection lines (single JOIN instead of 2 queries)
        $baseIds = $baseUnits->pluck('id')->toArray();
        $allIds = array_unique(array_merge($baseIds, Unit::ancestorIds($baseIds)->toArray()));

        $this->location = Unit::whereIn('id', $allIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->select(['id', 'name', 'lat', 'lng', 'unit_type_id', 'parent_id', 'region_id'])
            ->get()
            ->toArray();
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
                <div data-filter-group="regions">
                    <label class="font-bold block mb-2">انتخاب شهرستان</label>
                    @foreach ($regions as $region)
                        <x-toggle
                            value="{{ $region['id'] }}"
                            label="{{ $region['name'] }}"
                        />
                    @endforeach
                </div>

                <div data-filter-group="types">
                    <label class="font-bold block mb-2">انتخاب نوع</label>
                    @foreach ($types as $type)
                        <x-toggle
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

    // Issue #028 — take a LIVE map from the store instead of waiting for a
    // `window.map` that a previous page may still own. onReady() removes the
    // script-ordering race with maps.map entirely.
    window.Alpine.store('map').onReady(function (map) {
        const markersLayer = L.layerGroup().addTo(map);
        const linesLayer = L.layerGroup().addTo(map);

        // Attach the shared canvas renderer up front: it registers its
        // zoomanim listener once, here, instead of whenever the first line
        // happens to be drawn.
        if (!lineRenderer) lineRenderer = L.canvas({ padding: 0.5 });
        lineRenderer.addTo(map);

        // The locations already crossed the wire once, inside this
        // component's Livewire snapshot — read them from $wire instead of
        // embedding a second copy of the payload in the page (issue #776).
        const allLocations = $wire.get('location') ?? [];
        const byId = new Map(allLocations.map(l => [l.id, l]));

        // Perf: memoised depth (was: a full re-scan per node — O(N·depth)).
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

        // Client-side filtering with the same semantics the server query had:
        // a unit is visible when it matches the selected regions AND types,
        // and every visible unit's ancestors stay visible so the connection
        // lines keep their parent markers.
        function visibleLocations() {
            const selected = (group) => new Set(
                [...document.querySelectorAll(`[data-filter-group="${group}"] input[type="checkbox"]:checked`)]
                    .map(cb => Number(cb.value))
            );
            const regions = selected('regions');
            const types = selected('types');
            if (!regions.size && !types.size) return allLocations;

            const visible = new Map();
            for (const loc of allLocations) {
                if (regions.size && !regions.has(loc.region_id)) continue;
                if (types.size && !types.has(loc.unit_type_id)) continue;
                let cur = loc;
                while (cur && !visible.has(cur.id)) {
                    visible.set(cur.id, cur);
                    cur = byId.get(cur.parent_id);
                }
            }
            return [...visible.values()];
        }

        // Perf: chunked rendering — building ~800 markers + ~800 lines in one
        // synchronous pass froze the main thread for ~1s on load and on every
        // filter switch. Markers go first (they are what the user waits for),
        // in requestAnimationFrame slices, so the page never locks up.
        let renderToken = 0;
        function renderMarkers(locations) {
            const token = ++renderToken;
            markersLayer.clearLayers();
            linesLayer.clearLayers();

            const linePairs = [];
            for (const loc of locations) {
                if (!loc.parent_id) continue;
                const parent = byId.get(loc.parent_id);
                if (parent && parent.lat && parent.lng) linePairs.push([loc, parent]);
            }

            const MARKER_CHUNK = 250;
            const LINE_CHUNK = 400;
            let mi = 0;
            let li = 0;

            const markerStep = () => {
                if (token !== renderToken) return;
                const end = Math.min(mi + MARKER_CHUNK, locations.length);
                for (; mi < end; mi++) {
                    const loc = locations[mi];
                    L.marker(
                        [loc.lat, loc.lng],
                        { icon: getIcon(loc.unit_type_id) }
                    )
                        .bindPopup(loc.name)
                        .addTo(markersLayer);
                }
                if (mi < locations.length) requestAnimationFrame(markerStep);
                else requestAnimationFrame(lineStep);
            };

            const lineStep = () => {
                if (token !== renderToken) return;
                const end = Math.min(li + LINE_CHUNK, linePairs.length);
                for (; li < end; li++) {
                    const [loc, parent] = linePairs[li];
                    const color = lineColors[Math.min(depthOf(parent.id), lineColors.length - 1)];
                    L.polyline(
                        [[loc.lat, loc.lng], [parent.lat, parent.lng]],
                        { color, weight: 2, opacity: 0.7, dashArray: '6 4', renderer: lineRenderer }
                    ).addTo(linesLayer);
                }
                if (li < linePairs.length) requestAnimationFrame(lineStep);
            };

            requestAnimationFrame(markerStep);
        }

        // Render initial locations
        renderMarkers(allLocations);

        // Filter toggles re-render locally — no Livewire round-trip.
        document.querySelectorAll('.controls-panel input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', () => renderMarkers(visibleLocations()));
        });
    });
</script>
@endscript
