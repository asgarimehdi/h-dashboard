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

        if (! $unit || ! $unit->boundary_id) {
            return;
        }

        $boundaryId = $unit->boundary_id;

        // #702: nulling boundary_id alone left the geometry row orphaned in
        // `boundaries` forever.
        //
        // ORDER MATTERS: units.boundary_id is ON DELETE CASCADE, so deleting the
        // `boundaries` row first would cascade-delete THIS unit (and any child
        // units). Null the FK first so the boundary row is unreferenced, then
        // remove it.
        try {
            DB::transaction(function () use ($unit, $boundaryId): void {
                $unit->update(['boundary_id' => null]);
                DB::table('boundaries')->where('id', $boundaryId)->delete();
            });
        } catch (\Throwable $e) {
            report($e);
            $this->error('حذف مرز ناموفق بود.');

            return;
        }

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
    var mapTileTemplate = @js(config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'));

    function initUnitMap() {
        var unitMap = L.map('unitMap').setView([36.558188, 48.716125], 8);

        L.tileLayer(mapTileTemplate, {
            attribution: '&copy; Health-Dashboard',
            className: 'map-tiles'
        }).addTo(unitMap);

        var drawnItems = new L.FeatureGroup();
        window._drawnItems = drawnItems;
        unitMap.addLayer(drawnItems);

        var existingLayer = null;
        var initialGeojson = @js($geojson);

        if (initialGeojson) {
            try {
                var data = typeof initialGeojson === 'string' ? JSON.parse(initialGeojson) : initialGeojson;

                // #702: boundaries are stored as MultiPolygon (PostGIS) and
                // Boundary::getGeojsonAttribute() returns ST_AsGeoJSON's BARE
                // geometry ({type, coordinates}) — not a GeoJSON Feature. Two bugs
                // followed from that:
                //   1. L.geoJSON() was handed a geometry with no `geometry` key, so
                //      the layer was empty and fitBounds threw "Bounds are not valid",
                //      leaving the draw toolbar permanently disabled.
                //   2. Even when a layer did load, MultiPolygon gave it THREE levels
                //      of nesting ([[ring]] -> [1] => [1] => [19] => LatLng).
                //      L.Edit.Poly only understands two ([ring]), so it enabled
                //      without error but rendered zero vertex handles and the
                //      boundary was not editable.
                // Normalise both: accept a bare geometry or a Feature, and unwrap
                // the single MultiPolygon to a plain Polygon so L.Edit.Poly works.
                var geometry = (data && data.geometry) ? data.geometry : data;
                if (geometry && geometry.type === 'MultiPolygon' && Array.isArray(geometry.coordinates)) {
                    geometry = {
                        type: 'Polygon',
                        coordinates: geometry.coordinates[0] || [],
                    };
                }

                existingLayer = L.geoJSON({ type: 'Feature', properties: {}, geometry: geometry }, {
                    style: { color: '#f59e0b', weight: 3, opacity: 0.8, fillOpacity: 0.15 }
                }).addTo(unitMap);

                // #702: the draw toolbar's edit/remove tools only operate on layers
                // inside `drawnItems`. The saved boundary was added straight to the
                // map, so it got no edit handles and the toolbar could not delete it —
                // only the bottom "حذف مرز" button worked.
                //
                // Add the INDIVIDUAL polygon layers (eachLayer), never the L.GeoJSON
                // group itself: updateGeojson() iterates getLayers() and only handles
                // L.Polygon instances.
                existingLayer.eachLayer(function (layer) {
                    drawnItems.addLayer(layer);
                });

                unitMap.fitBounds(drawnItems.getBounds());

                // #702: seed _mapGeojson from the loaded boundary. Without this,
                // _mapGeojson stayed undefined until the user drew something, and
                // saveMapBoundary() reads that as "nothing to save" and routed to
                // deleteBoundary() — so simply opening a unit with a boundary and
                // pressing ذخیره deleted it.
                updateGeojson();
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
            // #702: a unit has exactly one boundary, so a newly drawn polygon
            // replaces the previous one. The old layer now lives inside
            // `drawnItems`, so clearing the group replaces the previous
            // "remove existingLayer from map" logic.
            drawnItems.clearLayers();

            var layer = event.layer;
            if (layer instanceof L.Rectangle) {
                layer = L.polygon(layer.getLatLngs()[0], layer.options);
            }

            drawnItems.addLayer(layer);
            updateGeojson();
        });

        unitMap.on('draw:edited', function() {
            updateGeojson();
        });

        unitMap.on('draw:deleted', function() {
            updateGeojson();
        });
    }

    function updateGeojson() {
        // Access drawnItems from the closure - use global reference
        var layers = window._drawnItems ? window._drawnItems.getLayers() : [];
        if (layers.length === 0) {
            window._mapGeojson = null;
            return;
        }
        var coords = [];
        layers.forEach(function(layer) {
            if (layer instanceof L.Polygon && !(layer instanceof L.Rectangle)) {
                // #702: getLatLngs() is [ring] for a plain polygon but [[ring]] for
                // one built from a MultiPolygon geometry. Normalise to the real
                // ring before mapping coords, otherwise ring.map() walks arrays
                // instead of LatLngs and yields [undefined, undefined, ...].
                var latlngs = layer.getLatLngs();
                var ring = latlngs;
                while (Array.isArray(ring) && Array.isArray(ring[0])) {
                    ring = ring[0];
                }
                if (!Array.isArray(ring) || ring.length === 0) return;

                var pts = ring.map(function(ll) { return [ll.lng, ll.lat]; });
                if (pts.length > 0) {
                    var first = pts[0], last = pts[pts.length - 1];
                    if (first[0] !== last[0] || first[1] !== last[1]) pts.push(first);
                }
                if (pts.length > 0) coords.push([pts]);
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
            $wire.deleteBoundary();
        }
    };

    $wire.on('boundaryUpdated', function() {
        window.location.reload();
    });

    // Wait for the #unitMap DOM element to exist
    if (document.getElementById('unitMap')) {
        initUnitMap();
    } else {
        var tries = 0;
        var waitForEl = setInterval(() => {
            tries++;
            if (document.getElementById('unitMap')) {
                clearInterval(waitForEl);
                initUnitMap();
            } else if (tries > 50) {
                clearInterval(waitForEl);
                console.error('Map container #unitMap not found within 10s');
            }
        }, 200);
    }
</script>
@endscript
