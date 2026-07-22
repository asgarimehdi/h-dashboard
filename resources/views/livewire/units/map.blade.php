<?php

use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component {
    use Toast;

    public int $unitId;
    public ?array $unit = null;
    public ?string $geojson = null;
    public bool $hasBoundary = false;

    public function mount(int $id): void
    {
        $this->unitId = $id;
        $this->loadUnit();
    }

    public function loadUnit(): void
    {
        $unit = Unit::with('boundary')->find($this->unitId);

        if (! $unit) {
            $this->error('واحد یافت نشد.');
            return;
        }

        $this->unit = $unit->toArray();
        $this->hasBoundary = $unit->boundary_id !== null;
        $this->geojson = $unit->boundary?->geojson;
    }

    public function saveBoundary($geojsonData): void
    {
        $feature = json_decode($geojsonData, true);

        if (! isset($feature['geometry']['type']) || ! in_array($feature['geometry']['type'], ['Polygon', 'MultiPolygon'])) {
            $this->error('فقط نوع‌های Polygon یا MultiPolygon پشتیبانی می‌شوند.');
            return;
        }

        $geometry = json_encode($feature['geometry']);

        $unit = Unit::find($this->unitId);
        if (! $unit) return;

        if ($unit->boundary_id) {
            DB::table('boundaries')
                ->where('id', $unit->boundary_id)
                ->update([
                    'boundary' => DB::raw("ST_GeomFromGeoJSON(" . DB::getPdo()->quote($geometry) . ")"),
                ]);
        } else {
            $boundaryId = DB::table('boundaries')->insertGetId([
                'boundary' => DB::raw("ST_GeomFromGeoJSON(" . DB::getPdo()->quote($geometry) . ")"),
            ]);
            $unit->update(['boundary_id' => $boundaryId]);
        }

        $this->success('مرز با موفقیت ذخیره شد.');
        $this->loadUnit();
        $this->dispatch('boundaryUpdated');
    }

    public function deleteBoundary(): void
    {
        $unit = Unit::find($this->unitId);
        if (! $unit || ! $unit->boundary_id) return;

        $unit->update(['boundary_id' => null]);
        $this->success('مرز حذف شد.');
        $this->loadUnit();
        $this->dispatch('boundaryUpdated');
    }
}; ?>

<div>
    <x-header title="نقشه: {{ $unit['name'] ?? '' }}" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex items-center gap-3 mb-4">
            <a href="/units" class="btn btn-ghost btn-sm">
                <x-icon name="o-arrow-left" class="w-5 h-5"/>
                بازگشت
            </a>
            @if($hasBoundary)
                <span class="badge badge-success badge-sm">مرز تعریف شده</span>
            @else
                <span class="badge badge-warning badge-sm">بدون مرز</span>
            @endif
        </div>

        <div wire:ignore>
            <div id="unitMap" class="h-[70vh] rounded-lg"></div>
        </div>

        <div class="flex justify-end gap-2 mt-4">
            <button type="button" class="btn btn-primary" onclick="saveMapBoundary()">
                <x-icon name="o-check" class="w-5 h-5"/>
                ذخیره
            </button>
            @if($hasBoundary)
                <x-button label="حذف مرز" icon="o-trash" class="btn-error" wire:click="deleteBoundary"
                          wire:confirm="آیا از حذف مرز مطمئن هستید؟"/>
            @endif
        </div>
    </x-card>
</div>

@assets
<style>
    #unitMap { z-index: 0; }
    .dark .leaflet-layer,
    .dark .leaflet-control-zoom-in,
    .dark .leaflet-control-zoom-out,
    .dark .leaflet-control-attribution {
        filter: invert(100%) hue-rotate(180deg) brightness(100%) contrast(100%);
    }
</style>
@endassets

@script
<script>
    var unitMap = L.map('unitMap').setView([36.558188, 48.716125], 8);
    var mapIp = @js(config('map.tile_server_ip', '10.100.252.137'));

    L.tileLayer('http://' + mapIp + ':8080/tile/{z}/{x}/{y}.png', {
        attribution: '&copy; Health-Dashboard',
        className: 'map-tiles'
    }).addTo(unitMap);

    var drawnItems = new L.FeatureGroup();
    unitMap.addLayer(drawnItems);

    var existingLayer = null;
    var initialGeojson = @js($geojson);

    if (initialGeojson) {
        try {
            var data = typeof initialGeojson === 'string' ? JSON.parse(initialGeojson) : initialGeojson;
            existingLayer = L.geoJSON(data, {
                style: { color: '#f59e0b', weight: 3, opacity: 0.8, fillOpacity: 0.15 }
            }).addTo(unitMap);
            unitMap.fitBounds(existingLayer.getBounds());
        } catch (e) {
            console.error('Error loading existing boundary:', e);
        }
    }

    var drawControl = new L.Control.Draw({
        edit: { featureGroup: drawnItems, remove: true },
        draw: {
            polygon: true,
            polyline: false,
            rectangle: false,
            circle: false,
            marker: false,
            circlemarker: false
        }
    });
    unitMap.addControl(drawControl);

    unitMap.on('draw:created', function(event) {
        if (existingLayer) {
            unitMap.removeLayer(existingLayer);
            existingLayer = null;
        }
        drawnItems.addLayer(event.layer);
        updateGeojson();
    });

    unitMap.on('draw:edited', function() {
        updateGeojson();
    });

    unitMap.on('draw:deleted', function() {
        updateGeojson();
    });

    function updateGeojson() {
        var layers = drawnItems.getLayers();
        if (layers.length === 0) {
            window._mapGeojson = null;
            return;
        }
        var coords = [];
        layers.forEach(function(layer) {
            if (layer instanceof L.Polygon && !(layer instanceof L.Rectangle)) {
                var latlngs = layer.getLatLngs()[0];
                var ring = latlngs.map(function(ll) { return [ll.lng, ll.lat]; });
                if (ring.length > 0) {
                    var first = ring[0], last = ring[ring.length - 1];
                    if (first[0] !== last[0] || first[1] !== last[1]) ring.push(first);
                }
                coords.push([ring]);
            }
        });
        if (coords.length === 0) {
            window._mapGeojson = null;
            return;
        }
        window._mapGeojson = JSON.stringify({
            type: "Feature",
            properties: {},
            geometry: { type: "MultiPolygon", coordinates: coords }
        });
    }

    window.saveMapBoundary = function() {
        if (window._mapGeojson) {
            $wire.saveBoundary(window._mapGeojson);
        } else {
            // No drawn polygons — if existing boundary was removed, just save empty
            $wire.deleteBoundary();
        }
    };

    // Handle boundaryUpdated event to refresh map
    $wire.on('boundaryUpdated', function() {
        // Reload page to refresh the map state cleanly
        window.location.reload();
    });
</script>
@endscript
