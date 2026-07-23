<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Unit;
use App\Services\AccessService;

return new class extends Component
{
    public $units = [];

    public function mount(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $this->units = Unit::whereIn('id', $accessibleIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->select('id', 'name', 'lat', 'lng', 'unit_type_id', 'parent_id')
            ->with('unitType')
            ->get()
            ->toArray();
    }

}; ?>
    {{-- Units Map --}}
    <div class="p-6" dir="rtl">
        <h1 class="text-2xl font-bold mb-6 flex items-center gap-2">
            <x-icon name="o-map-pin" class="w-7 h-7 text-primary" />
            نقشه واحدهای سازمانی
        </h1>

        <x-card shadow class="mb-6">
            <div class="flex gap-3 flex-wrap items-center mb-4">
                <p class="text-sm opacity-70">تعداد واحدها با مختصات: <span class="font-bold text-primary">{{ count($units) }}</span></p>
                <button id="fitBoundsBtn" class="btn btn-ghost btn-sm">نمایش همه</button>
            </div>
            <div id="unitsMap" style="height: 600px;"></div>
        </x-card>

        {{-- Units List Sidebar --}}
        <x-card shadow>
            <h3 class="font-bold mb-3">لیست واحدها</h3>
            <div class="max-h-96 overflow-y-auto">
                @foreach($units as $unit)
                <div class="flex items-center gap-2 p-2 hover:bg-base-200/50 rounded cursor-pointer" data-id="{{ $unit['id'] }}" data-lat="{{ $unit['lat'] }}" data-lng="{{ $unit['lng'] }}">
                    <x-icon name="o-building-office" class="w-5 h-5 text-primary" />
                    <span class="text-sm">{{ $unit['name'] }}</span>
                    @if($unit['unit_type'])
                    <x-badge value="{{ $unit['unit_type']['name'] }}" class="badge-ghost badge-sm" />
                    @endif
                </div>
                @endforeach
            </div>
        </x-card>
    </div>

    @script
    <script>
        function getDepth(unit, allUnits) {
            let depth = 0;
            let current = unit;
            while (current && current.parent_id) {
                current = allUnits.find(u => u.id === current.parent_id);
                depth++;
            }
            return depth;
        }

        function initInteractiveMap() {
            const map = L.map('unitsMap').setView([35.6892, 51.3890], 7);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19,
            }).addTo(map);

            const units = @json($units);
            const markers = {};

            units.forEach(unit => {
                if (unit.lat && unit.lng) {
                    const color = unit.unit_type?.name === 'مرکز' ? '#ef4444' :
                                  unit.unit_type?.name === 'شعبه' ? '#3b82f6' : '#22c55e';

                    const marker = L.circleMarker([parseFloat(unit.lat), parseFloat(unit.lng)], {
                        radius: 8,
                        fillColor: color,
                        color: '#fff',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.8
                    }).addTo(map);

                    marker.bindPopup(`
                        <div dir="rtl" style="font-family: Vazirmatn, sans-serif;">
                            <strong>${unit.name}</strong><br>
                            نوع: ${unit.unit_type?.name || 'نامشخص'}
                        </div>
                    `);

                    markers[unit.id] = marker;
                }
            });

            // Draw connection lines between parent and child units
            const lineColors = ['#14b8a6', '#3b82f6', '#f97316', '#a855f7', '#ef4444'];
            const linesLayer = L.layerGroup().addTo(map);
            units.forEach(unit => {
                if (unit.parent_id && markers[unit.parent_id] && markers[unit.id]) {
                    const parent = units.find(u => u.id === unit.parent_id);
                    const depth = getDepth(parent, units);
                    const color = lineColors[Math.min(depth, lineColors.length - 1)];
                    L.polyline(
                        [[parseFloat(unit.lat), parseFloat(unit.lng)],
                         [parseFloat(parent.lat), parseFloat(parent.lng)]],
                        { color, weight: 2, opacity: 0.7, dashArray: '6 4' }
                    ).addTo(linesLayer);
                }
            });

            const allLayers = [...Object.values(markers)];
            linesLayer.eachLayer(l => allLayers.push(l));
            const group = L.featureGroup(allLayers);
            if (Object.keys(markers).length > 0) {
                map.fitBounds(group.getBounds().pad(0.1));
            }

            document.querySelectorAll('[data-id]').forEach(item => {
                item.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const lat = parseFloat(this.dataset.lat);
                    const lng = parseFloat(this.dataset.lng);
                    if (markers[id]) {
                        map.setView([lat, lng], 15);
                        markers[id].openPopup();
                    }
                });
            });

            document.getElementById('fitBoundsBtn').addEventListener('click', () => {
                if (Object.keys(markers).length > 0) {
                    map.fitBounds(group.getBounds().pad(0.1));
                }
            });
        }

        if (document.getElementById('unitsMap')) {
            initInteractiveMap();
        } else {
            var tries = 0;
            var waitForEl = setInterval(() => {
                tries++;
                if (document.getElementById('unitsMap')) {
                    clearInterval(waitForEl);
                    initInteractiveMap();
                } else if (tries > 50) {
                    clearInterval(waitForEl);
                    console.error('Map container #unitsMap not found within 10s');
                }
            }, 200);
        }
    </script>
    @endscript
