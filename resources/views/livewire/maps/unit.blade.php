<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\Region;
use App\Services\AccessService;
use Livewire\Component;
use App\Services\CacheInvalidationServiceInterface;

return new class extends Component
{
    public bool $showHelpModal = false;

    /**
     * #1188: the unit rows the toggle table renders — id/name/type_id only.
     *
     * The geometry is deliberately NOT here: this property is public, so it is
     * dehydrated into the snapshot of EVERY round trip, and the search box
     * fires one round trip per keystroke — the whole county geometry used to
     * travel back and forth with it. The geometry leaves the component through
     * the `units-updated` event payload alone (see unitsWithGeometry()).
     */
    public $units = [];

    public $regions = [];
    public $centerTypes = [];
    public $subTypes = [];

    public array $selectedRegions = [];
    public array $selectedCenterTypes = [];
    public array $selectedSubTypes = [];
    public string $search = '';

    private const SUB_TYPE_MAP = [
        'خانه بهداشت' => [9, 18],
        'پایگاه'      => [8, 10],
        'قمر'         => [12],
    ];

    public function mount(): void
    {
        // #1032: the county toggles are scoped to the caller's accessible units,
        // the same rule maps/county applies. Fail-closed — an empty scope makes
        // the IN-filter compile to `0 = 1`, so a unit-less caller sees no county.
        // `Unit::query()` (not `Unit::whereIn`) keeps the chain on Eloquent's
        // Builder, which PHPStan understands without larastan.
        $accessibleRegionIds = Unit::query()
            ->whereIn('id', app(AccessService::class)->accessibleUnitIds())
            ->whereNotNull('region_id')
            ->pluck('region_id');

        $this->regions = app(CacheInvalidationServiceInterface::class)->remember('maps', \App\Support\ScopeCacheKey::hash($accessibleRegionIds->all()), function () use ($accessibleRegionIds) {
            return Region::query()
                ->where('type', 'county')
                ->whereIn('id', $accessibleRegionIds)
                ->select('id', 'name')
                ->get()
                ->toArray();
        }, 5, ['segment' => 'unit_map:regions']);

        $this->centerTypes = app(CacheInvalidationServiceInterface::class)->remember('maps', 'global', function () {
            return UnitType::whereIn('id', [5, 6, 7])
                ->select('id', 'name')
                ->get()
                ->toArray();
        }, 5, ['segment' => 'unit_map:center_types']);

        $this->subTypes = [
            ['name' => 'خانه بهداشت'],
            ['name' => 'پایگاه'],
            ['name' => 'قمر'],
        ];
    }

    public function updatedSelectedRegions(): void
    {
        $this->showSelectedCounties();
        $this->loadUnits();
    }

    private function showSelectedCounties(): void
    {
        if (empty($this->selectedRegions)) {
            $this->dispatch('county-boundaries-loaded', counties: []);
            return;
        }

        // #1032: boundaries are scoped exactly like maps/county — a caller only
        // receives geometry for regions an accessible unit sits in. The scope is
        // part of the cache key too: with a shared key, a boundary cached by a
        // broad-scope user was served to a narrower one on the next hit, which
        // bypassed the query filter entirely. Do not drop either half.
        $accessibleRegionIds = Unit::query()
            ->whereIn('id', app(AccessService::class)->accessibleUnitIds())
            ->whereNotNull('region_id')
            ->pluck('region_id');

        $counties = app(CacheInvalidationServiceInterface::class)->remember('maps', \App\Support\ScopeCacheKey::hash($accessibleRegionIds->all()), function () use ($accessibleRegionIds) {
            // #1023: project ST_AsGeoJSON in the query (the pattern county.blade.php
            // already uses) instead of reading Boundary::$appends['geojson'] per
            // row, which fired one query per region and round-tripped the geometry.
            // DB is referenced fully-qualified on purpose: a `use` line here would
            // push `return new class` off line 10 and break the line-keyed PHPStan
            // baseline anchors for this anonymous component.
            return Region::whereIn('regions.id', $this->selectedRegions)
                ->whereIn('regions.id', $accessibleRegionIds)
                ->whereNotNull('regions.boundary_id')
                ->join('boundaries', 'regions.boundary_id', '=', 'boundaries.id')
                ->select([
                    'regions.id',
                    'regions.name',
                    \Illuminate\Support\Facades\DB::raw('ST_AsGeoJSON(boundaries.boundary) as geojson'),
                ])
                ->get()
                ->map(fn($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'geojson' => $r->geojson,
                ])
                ->filter(fn($r) => $r['geojson'])
                ->values()
                ->toArray();
        }, 5, ['segment' => 'county_boundaries', 'regions' => \App\Support\ScopeCacheKey::hash($this->selectedRegions)]);

        $this->dispatch('county-boundaries-loaded', counties: $counties);
    }

    public function updatedSelectedSubTypes(): void
    {
        $this->loadUnits();
    }

    public function updatedSelectedCenterTypes(): void
    {
        $this->loadUnits();
    }

    /**
     * #1188: the unit rows WITH their geometry — the non-public source the
     * `units-updated` event is built from, read only when it is dispatched.
     *
     * A private method, not a Livewire `#[Computed]`: a computed is read as a
     * magic property (`$this->unitsWithGeometry`), which PHPStan cannot
     * resolve on this anonymous class — it would need a new baseline entry,
     * and the baseline is not allowed to grow. This is the same trade #1040
     * made in users/index.blade.php, where the equivalent cache lives in a
     * private member: Livewire 4 dehydrates public properties only, so the
     * geometry never enters the snapshot either way.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unitsWithGeometry(): array
    {
        if (empty($this->selectedRegions)) {
            return [];
        }

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // Collect query results first, then combine — avoids merge()/map()
        // on an empty untyped collect() (Collection<NEVER,NEVER>).
        $unitLists = [];

        // Centers (types 5, 6, 7) — filtered by selected center types
        $parentIds = collect();
        if (!empty($this->selectedCenterTypes)) {
            $centers = Unit::whereIn('units.id', $accessibleIds)  // Organizational Scope
                ->whereNotNull('units.boundary_id')
                ->whereIn('units.region_id', $this->selectedRegions)
                ->whereIn('units.unit_type_id', $this->selectedCenterTypes)
                ->join('boundaries', 'units.boundary_id', '=', 'boundaries.id')
                ->select([
                    'units.id',
                    'units.name',
                    'units.unit_type_id',
                    'units.boundary_id',
                    \Illuminate\Support\Facades\DB::raw('ST_AsGeoJSON(boundaries.boundary) as geojson'),
                ])
                ->get();
            $unitLists[] = $centers;

            // Get IDs of selected centers from the already-loaded collection (in-memory, no extra query)
            $parentIds = $centers->pluck('id');
        }

        // Sub-types (خانه بهداشت, پایگاه, قمر) — only if their parent center type is selected
        if (!empty($this->selectedSubTypes) && !empty($this->selectedCenterTypes)) {
            $subTypeIds = $this->resolveSubTypeIds();

            $unitLists[] = Unit::whereIn('units.id', $accessibleIds)  // Organizational Scope
                ->whereNotNull('units.boundary_id')
                ->whereIn('units.region_id', $this->selectedRegions)
                ->whereIn('units.unit_type_id', $subTypeIds)
                ->whereIn('units.parent_id', $parentIds)
                ->join('boundaries', 'units.boundary_id', '=', 'boundaries.id')
                ->select([
                    'units.id',
                    'units.name',
                    'units.unit_type_id',
                    'units.boundary_id',
                    \Illuminate\Support\Facades\DB::raw('ST_AsGeoJSON(boundaries.boundary) as geojson'),
                ])
                ->get();
        }

        $units = collect($unitLists)->collapse();

        // The event payload keeps the row shape it always had:
        // id, name, type_id, geojson.
        return $units->map(fn($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'type_id' => $u->unit_type_id,
            'geojson' => $u->geojson,
        ])->values()->toArray();
    }

    public function loadUnits(): void
    {
        // #1188: the geometry leaves the component ONLY through the event
        // payload. The public state keeps what the toggle table needs
        // (id/name/type_id) and nothing more — it is dehydrated into the
        // snapshot of every round trip, and the search box fires one round
        // trip per keystroke.
        $rows = $this->unitsWithGeometry();

        $this->units = collect($rows)
            ->map(fn($u) => [
                'id' => $u['id'],
                'name' => $u['name'],
                'type_id' => $u['type_id'],
            ])
            ->values()
            ->all();

        $this->dispatch('units-updated', units: $rows);
    }

    private function resolveSubTypeIds(): array
    {
        $typeIds = [];
        foreach ($this->selectedSubTypes as $name) {
            $typeIds = array_merge($typeIds, self::SUB_TYPE_MAP[$name] ?? []);
        }
        return array_unique($typeIds);
    }
};
?>

<div>
    <x-header title="نقشه واحد ها" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="maps" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    {{-- Filter bar above the map --}}
    <x-card shadow class="mb-4 p-3">
        <div class="flex flex-wrap items-center gap-4">
            {{-- Counties --}}
            <div class="flex flex-wrap items-center gap-1">
                <label class="font-bold text-sm ml-1">شهرستان:</label>
                @foreach ($regions as $region)
                    <x-toggle
                        right
                        wire:model.live="selectedRegions"
                        value="{{ $region['id'] }}"
                        label="{{ $region['name'] }}"
                    />
                @endforeach
            </div>

            @if (!empty($selectedRegions))
                <hr class="border-base-300" />

                {{-- Center types --}}
                <div class="flex flex-wrap items-center gap-1">
                    <label class="font-bold text-sm ml-1">مرکز:</label>
                    @foreach ($centerTypes as $type)
                        <x-toggle
                            right
                            wire:model.live="selectedCenterTypes"
                            value="{{ $type['id'] }}"
                            label="{{ $type['name'] }}"
                        />
                    @endforeach
                </div>

                <hr class="border-base-300" />

                {{-- Sub-types --}}
                <div class="flex flex-wrap items-center gap-1">
                    <label class="font-bold text-sm ml-1">نوع مرکز:</label>
                    @foreach ($subTypes as $type)
                        <x-toggle
                            right
                            wire:model.live="selectedSubTypes"
                            value="{{ $type['name'] }}"
                            label="{{ $type['name'] }}"
                        />
                    @endforeach
                </div>
            @endif
        </div>
    </x-card>

    {{-- Map + right-side unit list --}}
    <x-card shadow class="p-0">
        <div class="relative">
            <livewire:maps.map wire:key="unit-page-map" />

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

                {{-- Unit list --}}
                @foreach ($units as $unit)
                    <x-toggle
                        right
                        label="{{ $unit['name'] }}"
                        wire:key="unit-{{ $unit['id'] }}"
                        x-on:change="$event.target.checked ? toggleGeoJsonOn({{ $unit['id'] }}) : toggleGeoJsonOff({{ $unit['id'] }})"
                    />
                @endforeach

                @if (!empty($selectedRegions) && (!empty($selectedCenterTypes) || !empty($selectedSubTypes)) && empty($units))
                    <p class="text-sm text-base-content/50 text-center">واحدی یافت نشد</p>
                @endif
            </div>
        </div>
    </x-card>
</div>

@assets
<style nonce="{{ Vite::cspNonce() }}">
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
    // #1188: the geometry is NOT published here. This script runs once, on the
    // first render, when no filter is picked yet — so this was always an empty
    // array. The unit rows (with their geometry) arrive with the
    // `units-updated` event and are handed to `allUnits` by its listener below.
    var allUnits = [];
    var countyLayers = {};
    var activeToggles = {};

    function clearAllLayers(map) {
        Object.keys(geojsonLayers).forEach(function(id) {
            map.removeLayer(geojsonLayers[id]);
            delete geojsonLayers[id];
        });
    }

    function clearCountyLayers(map) {
        Object.keys(countyLayers).forEach(function(id) {
            map.removeLayer(countyLayers[id]);
            delete countyLayers[id];
        });
    }

    function showCountyBoundaries(map, counties) {
        clearCountyLayers(map);
        counties.forEach(function(c) {
            if (c.geojson && !countyLayers[c.id]) {
                try {
                    let data = typeof c.geojson === 'string' ? JSON.parse(c.geojson) : c.geojson;
                    countyLayers[c.id] = L.geoJSON(data, {
                        style: { color: "#3b82f6", weight: 2, opacity: 0.7, fillOpacity: 0.05 }
                    }).addTo(map);
                } catch (e) {
                    console.error('Error adding county boundary:', e);
                }
            }
        });
    }

    function showUnits(map, units) {
        units.forEach(function(unit) {
            if (unit.geojson && !geojsonLayers[unit.id]) {
                try {
                    let data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
                    geojsonLayers[unit.id] = L.geoJSON(data, {
                        style: { color: "orange", weight: 2, opacity: 0.8, fillOpacity: 0.1 }
                    }).addTo(map);
                    activeToggles[unit.id] = true;
                } catch (e) {
                    console.error('Error adding GeoJSON:', e);
                }
            }
        });
    }

    function syncToggleStates() {
        document.querySelectorAll('[wire\\:key^="unit-"]').forEach(function(el) {
            var input = el.querySelector('input[type="checkbox"]');
            if (!input) return;
            var key = el.getAttribute('wire:key');
            var unitId = parseInt(key.replace('unit-', ''));
            if (activeToggles[unitId]) {
                input.checked = true;
            }
        });
    }

    // Issue #028 — bind to the live map the store owns. onReady() replaces the
    // old `waitForMap`, whose "window.map exists" check a previous page's
    // detached instance satisfied, and it also removes the script-ordering race
    // with maps.map.
    window.Alpine.store('map').onReady(function (map) {
        window.toggleGeoJsonOn = function(unitId) {
            const unit = allUnits.find(u => u.id === unitId);
            if (!unit || !unit.geojson || geojsonLayers[unitId]) return;
            activeToggles[unitId] = true;
            try {
                let data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
                geojsonLayers[unitId] = L.geoJSON(data, {
                    style: { color: "orange", weight: 2, opacity: 0.8, fillOpacity: 0.1 }
                }).addTo(map);
                if (geojsonLayers[unitId].getBounds) {
                    map.fitBounds(geojsonLayers[unitId].getBounds().pad(0.1));
                }
            } catch (e) {
                console.error('Error parsing GeoJSON:', e);
            }
        };

        window.toggleGeoJsonOff = function(unitId) {
            delete activeToggles[unitId];
            if (geojsonLayers[unitId]) {
                map.removeLayer(geojsonLayers[unitId]);
                delete geojsonLayers[unitId];
            }
        };

        Livewire.on('county-boundaries-loaded', function({ counties }) {
            showCountyBoundaries(map, counties);
        });

        Livewire.on('units-updated', function({ units }) {
            clearAllLayers(map);
            allUnits = units;
            showUnits(map, allUnits);
            requestAnimationFrame(syncToggleStates);
        });
    });
</script>
@endscript