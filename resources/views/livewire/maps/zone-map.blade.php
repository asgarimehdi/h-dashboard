<div>
    <x-header title="نقشه زون‌ها" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="maps" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    {{-- Filter bar above the map --}}
    <x-card shadow class="mb-4 p-3">
        <div class="flex flex-wrap items-center gap-4">
            {{-- Zones --}}
            <div class="flex flex-wrap items-center gap-1">
                <label class="font-bold text-sm ml-1">زون‌ها:</label>
                @foreach ($availableZones as $zone)
                    <x-toggle
                        right
                        wire:model.live="selectedZoneIds"
                        value="{{ $zone['id'] }}"
                        label="{{ $zone['name'] }} ({{ $zone['units_count'] }})"
                    />
                @endforeach
            </div>

            @if (!empty($selectedZoneIds))
                <hr class="border-base-300" />

                {{-- Regions/Counties --}}
                <div class="flex flex-wrap items-center gap-1">
                    <label class="font-bold text-sm ml-1">شهرستان‌ها (برای مرجع):</label>
                    @foreach ($availableRegions as $region)
                        <x-toggle
                            right
                            wire:model.live="selectedRegions"
                            value="{{ $region['id'] }}"
                            label="{{ $region['name'] }}"
                        />
                    @endforeach
                </div>

                <hr class="border-base-300" />

                {{-- Units display toggle --}}
                <div class="flex items-center gap-2">
                    <label class="label cursor-pointer justify-start gap-2">
                        <input type="checkbox" wire:model="showUnits" class="checkbox checkbox-primary" />
                        <span class="label-text">نمایش واحدها</span>
                    </label>
                </div>
            @endif
        </div>
    </x-card>

    {{-- Map + right-side unit list --}}
    <x-card shadow class="p-0">
        <div class="relative">
            <livewire:maps.map/>

            <div class="unit-menu bg-base-100/60 rounded-l-box" id="unitMenu">
                {{-- Search --}}
                <div class="mb-3">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="جستجو در واحدها..."
                        class="input input-bordered input-sm w-full"
                    />
                </div>

                {{-- Zone legend --}}
                @if (!empty($selectedZoneIds))
                    <div class="mb-3 p-2 bg-base-200 rounded">
                        <label class="font-bold text-sm">نمایش زون‌ها:</label>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach ($availableZones as $zone)
                                @if (in_array($zone['id'], $selectedZoneIds))
                                    <span 
                                        class="badge badge-outline"
                                        style='border-color: {{ $zone["color"] }}; color: {{ $zone["color"] }}'>
                                        {{ $zone['name'] }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Unit list --}}
                @if ($showUnits)
                    <div wire:key="zone-unit-list" class="max-h-96 overflow-y-auto">
                        @foreach ($units as $unit)
                            <x-toggle
                                right
                                label="{{ $unit['name'] }} <span class='badge badge-ghost badge-xs' style='background-color: {{ $unit['zone_color'] }}; color: {{ $unit['zone_color'] }}'>{{ $unit['zone_name'] }}</span>"
                                wire:key="zone-unit-{{ $unit['id'] }}"
                                x-on:change="$event.target.checked ? toggleZoneUnitOn({{ $unit['id'] }}) : toggleZoneUnitOff({{ $unit['id'] }})"
                            />
                        @endforeach

                        @if (empty($units) && !empty($selectedZoneIds))
                            <p class="text-sm text-base-content/50 text-center py-4">واحدی یافت نشد</p>
                        @endif
                    </div>
                @endif
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
        width: 320px;
        max-height: 80vh;
        overflow-y: auto;
    }
</style>
@endassets

@script
<script>
    var geojsonLayers = {};
    var zoneBoundaryLayers = {};
    var allZoneUnits = [];
    var activeZoneUnits = {};

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
        Object.keys(zoneBoundaryLayers).forEach(function(id) {
            window.map.removeLayer(zoneBoundaryLayers[id]);
            delete zoneBoundaryLayers[id];
        });
        activeZoneUnits = {};
    }

    function showZoneBoundaries(boundaries) {
        boundaries.forEach(function(b) {
            if (b.geojson && !zoneBoundaryLayers[b.id]) {
                try {
                    let data = typeof b.geojson === 'string' ? JSON.parse(b.geojson) : b.geojson;
                    var style = {
                        color: b.color || '#3B82F6',
                        weight: 3,
                        opacity: 0.9,
                        fillOpacity: 0.1,
                        dashArray: '5, 5'
                    };
                    zoneBoundaryLayers[b.id] = L.geoJSON(data, {
                        style: style
                    }).addTo(window.map);
                } catch (e) {
                    console.error('Error adding zone boundary:', e);
                }
            }
        });
    }

    function showZoneUnits(units) {
        allZoneUnits = units;
        units.forEach(function(unit) {
            if (unit.geojson && !geojsonLayers[unit.id]) {
                try {
                    let data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
                    var style = {
                        color: unit.zone_color || 'orange',
                        weight: 2,
                        opacity: 0.8,
                        fillOpacity: 0.15
                    };
                    geojsonLayers[unit.id] = L.geoJSON(data, {
                        style: style
                    }).addTo(window.map);
                    activeZoneUnits[unit.id] = true;
                } catch (e) {
                    console.error('Error adding unit GeoJSON:', e);
                }
            }
        });
    }

    window.toggleZoneUnitOn = function(unitId) {
        if (!window.map) return;
        const unit = allZoneUnits.find(u => u.id === unitId);
        if (!unit || !unit.geojson || geojsonLayers[unitId]) return;
        activeZoneUnits[unitId] = true;
        try {
            let data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
            var style = {
                color: unit.zone_color || 'orange',
                weight: 2,
                opacity: 0.8,
                fillOpacity: 0.15
            };
            geojsonLayers[unitId] = L.geoJSON(data, {
                style: style
            }).addTo(window.map);
            if (geojsonLayers[unitId].getBounds) {
                map.fitBounds(geojsonLayers[unitId].getBounds().pad(0.1));
            }
        } catch (e) {
            console.error('Error parsing GeoJSON:', e);
        }
    };

    window.toggleZoneUnitOff = function(unitId) {
        delete activeZoneUnits[unitId];
        if (geojsonLayers[unitId]) {
            window.map.removeLayer(geojsonLayers[unitId]);
            delete geojsonLayers[unitId];
        }
    };

    function syncZoneToggleStates() {
        document.querySelectorAll('[wire\\\\:key^="zone-unit-"]').forEach(function(el) {
            var input = el.querySelector('input[type="checkbox"]');
            if (!input) return;
            var key = el.getAttribute('wire:key');
            var unitId = parseInt(key.replace('zone-unit-', ''));
            if (activeZoneUnits[unitId]) {
                input.checked = true;
            }
        });
    }

    waitForMap(function() {
        Livewire.on('zone-boundaries-loaded', function({ boundaries }) {
            showZoneBoundaries(boundaries);
        });

        Livewire.on('zone-units-loaded', function({ units }) {
            clearAllLayers();
            showZoneUnits(units);
            requestAnimationFrame(syncZoneToggleStates);
        });

        Livewire.on('county-boundaries-loaded', function({ counties }) {
            // Reuse existing county boundary display from unit map
            counties.forEach(function(c) {
                if (c.geojson && !geojsonLayers['county-' + c.id]) {
                    try {
                        let data = typeof c.geojson === 'string' ? JSON.parse(c.geojson) : c.geojson;
                        geojsonLayers['county-' + c.id] = L.geoJSON(data, {
                            style: { color: "#3b82f6", weight: 2, opacity: 0.7, fillOpacity: 0.05 }
                        }).addTo(window.map);
                    } catch (e) {
                        console.error('Error adding county boundary:', e);
                    }
                }
            });
        });
    });
</script>
@endscript