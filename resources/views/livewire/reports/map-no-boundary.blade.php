<?php

use Livewire\Component;
use App\Models\Unit;
use App\Services\AccessService;

return new class extends Component
{
    public $units = [];

    public function mount(): void
    {
    }

    public function chartPayload(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return Unit::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('id', $accessibleIds))
            ->whereNull('boundary_id')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->with('unitType:id,name', 'region:id,name')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'lat' => (float) $u->lat,
                'lng' => (float) $u->lng,
                'type' => $u->unitType?->name ?? '—',
                'region' => $u->region?->name ?? '—',
            ])
            ->toArray();
    }

    public function getAllUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return Unit::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('id', $accessibleIds))
            ->whereNull('boundary_id')
            ->with('unitType:id,name', 'region:id,name')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'lat' => $u->lat ? (float) $u->lat : null,
                'lng' => $u->lng ? (float) $u->lng : null,
                'type' => $u->unitType?->name ?? '—',
                'region' => $u->region?->name ?? '—',
                'has_coords' => !is_null($u->lat) && !is_null($u->lng),
            ])
            ->toArray();
    }
}; ?>

<div>
    <x-header title="نقاط فاقد مرز در نقشه" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    {{-- آمار --}}
    <div class="grid grid-cols-3 gap-4 mb-4 p-4">
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">کل واحدهای فاقد مرز</div>
            <div class="stat-value text-lg text-error">{{ count($this->allUnits) }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">دارای مختصات</div>
            <div class="stat-value text-lg text-success">{{ collect($this->allUnits)->where('has_coords', true)->count() }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs text-error">بدون مختصات</div>
            <div class="stat-value text-lg text-error">{{ collect($this->allUnits)->where('has_coords', false)->count() }}</div>
        </div>
    </div>

    <x-card shadow class="p-0">
        <div class="flex items-center gap-2 p-3 bg-warning/10 border-b border-warning/20">
            <svg class="w-5 h-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-sm">این واحدها فاقد مرز (polygon) هستند. نقاط قرمز روی نقشه واحدهایی هستند که مختصات دارند.</span>
        </div>
        <livewire:maps.map/>
    </x-card>

    {{-- لیست واحدهای بدون مختصات --}}
    @php $noCoords = collect($this->allUnits)->where('has_coords', false)->values(); @endphp
    @if($noCoords->isNotEmpty())
    <x-card shadow class="mt-4">
        <h3 class="font-bold mb-4">واحدهای فاقد مرز و مختصات</h3>
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr><th>#</th><th>نام</th><th>نوع</th><th>منطقه</th></tr>
                </thead>
                <tbody>
                    @foreach($noCoords as $u)
                    <tr>
                        <td>{{ $u['id'] }}</td>
                        <td>{{ $u['name'] }}</td>
                        <td>{{ $u['type'] }}</td>
                        <td>{{ $u['region'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
    @endif
</div>

@script
<script>
    function waitForMap(callback, attempts = 0) {
        if (window.map && typeof window.map.getSize === 'function') { callback(); return; }
        if (attempts > 100) { console.error('Map not ready'); return; }
        setTimeout(() => waitForMap(callback, attempts + 1), 50);
    }

    var markers = {};

    waitForMap(function() {
        var map = window.map;
        var noBoundaryUnits = {{ Js::from($this->allUnits) }};

        noBoundaryUnits.forEach(function(unit) {
            if (unit.has_coords && unit.lat && unit.lng) {
                var marker = L.circleMarker([unit.lat, unit.lng], {
                    radius: 8,
                    fillColor: '#ef4444',
                    color: '#fff',
                    weight: 2,
                    fillOpacity: 0.9
                }).addTo(map);

                marker.bindPopup('<strong>' + unit.name + '</strong><br>' + unit.type + '<br>' + unit.region);

                if (!markers[unit.id]) {
                    markers[unit.id] = [];
                }
                markers[unit.id].push(marker);
            }
        });

        // Fit map to markers
        var allLatLngs = Object.values(markers).flat().map(function(m) { return m.getLatLng(); });
        if (allLatLngs.length > 0) {
            map.fitBounds(allLatLngs, { padding: [30, 30] });
        }
    });
</script>
@endscript