<?php

use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\DB;

return new class extends Component {
    use Toast;

    public string $geojson = '';

    #[On('saveBoundary')]
    public function saveBoundary($geojsonData): void
    {
        $feature = json_decode($geojsonData, true);

        // چک کنیم که geometry وجود دارد و از نوع قابل قبول است
        if (!isset($feature['geometry']['type']) || !in_array($feature['geometry']['type'], ['Polygon', 'MultiPolygon'])) {
            $this->error("فقط نوع‌های Polygon یا MultiPolygon پشتیبانی می‌شوند.", position: 'toast-bottom');
            return;
        }

        $geometry = json_encode($feature['geometry']);

        $boundaryId = DB::table('boundaries')->insertGetId([
            'boundary' => DB::raw("ST_GeomFromGeoJSON(" . DB::getPdo()->quote($geometry) . ")")
        ]);
        
        // ارسال ایونت به والد
        $this->dispatch('boundarySaved', boundaryId: $boundaryId);
        $this->reset(['geojson']);
    }
};
?>

<div>
    <div wire:ignore>
        <livewire:maps.map/>
    </div>

    <div class="col-span-2 flex justify-end space-x-2 mt-4">
        <x-button label="ذخیره" icon="o-check" class="btn-primary" wire:click="saveBoundary($wire.geojson)"/>
        <x-button label="لغو" wire:click="$parent.resetForm" icon="o-x-mark" class="btn-outline"/>
    </div>
</div>

@assets
<style>
    #map {
        max-height: 400px;
    }
</style>
@endassets

@script
<script>
    // تابع کمکی برای چک کردن آماده بودن همه چیز
    function waitForMapAndDraw(callback) {
        if (window.map && 
            typeof window.map.getSize === 'function' && 
            typeof L.FeatureGroup === 'function' &&
            typeof L.Control.Draw === 'function') {
            callback();
        } else {
            setTimeout(() => waitForMapAndDraw(callback), 100);
        }
    }

    var geojson = '';

    // تنظیم ابزارهای رسم وقتی همه چیز آماده است
    waitForMapAndDraw(function() {
        try {
            var drawnItems = new L.FeatureGroup();
            window.map.addLayer(drawnItems);

            var drawControl = new L.Control.Draw({
                edit: {
                    featureGroup: drawnItems,
                    remove: true
                },
                draw: {
                    polygon: true,
                    polyline: false,
                    rectangle: false,
                    circle: false,
                    marker: false,
                    circlemarker: false
                }
            });
            
            window.map.addControl(drawControl);

            window.map.on('draw:created', (event) => {
                let layer = event.layer;
                drawnItems.addLayer(layer);

                // استخراج تمام مختصات پلی‌گان‌ها
                let allLayers = drawnItems.getLayers();
                let multiPolygonCoords = [];

                allLayers.forEach(function(layer) {
                    if (layer instanceof L.Polygon && !(layer instanceof L.Rectangle)) {
                        let latlngs = layer.getLatLngs()[0];
                        let coords = latlngs.map(function(latlng) {
                            return [latlng.lng, latlng.lat];
                        });

                        // بستن حلقه اگر بسته نیست
                        let first = coords[0];
                        let last = coords[coords.length - 1];
                        if (first[0] !== last[0] || first[1] !== last[1]) {
                            coords.push(first);
                        }

                        multiPolygonCoords.push([coords]);
                    }
                });

                // ساخت خروجی به صورت MultiPolygon
                let multiPolygonGeoJSON = {
                    type: "Feature",
                    properties: {},
                    geometry: {
                        type: "MultiPolygon",
                        coordinates: multiPolygonCoords
                    }
                };

                geojson = JSON.stringify(multiPolygonGeoJSON, null, 4);
                $wire.geojson = geojson;
            });

            console.log('✅ Draw controls initialized successfully');
        } catch (error) {
            console.error('❌ Error initializing draw controls:', error);
        }
    });
</script>
@endscript