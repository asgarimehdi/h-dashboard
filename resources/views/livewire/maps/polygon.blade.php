<?php

use Livewire\Attributes\On;
use Livewire\Volt\Component;
use App\Models\Boundary;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\DB;

new class extends Component {
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

        DB::table('boundaries')->insert([
            'boundary' => DB::raw("ST_GeomFromGeoJSON(" . DB::getPdo()->quote($geometry) . ")")
        ]);

        //$this->geojson = $geojsonData;
        $this->success("ایجاد شد", 'با موفقیت', position: 'toast-bottom');
    }


};
?>


<div>
    <style>
        #map {
            max-height: 400px;
        }

    </style>
    <x-header title="ذخیره اطلاعات مرزها" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
            <x-button wire:click="saveBoundary($wire.geojson)" class="mt-3 btn btn-primary" label="save"/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <div wire:ignore>
                <livewire:maps.map/>
            </div>
        </div>
    </x-card>
</div>


@script
<script>
    let geojson = '';

    var drawnItems = new L.FeatureGroup();
    map.addLayer(drawnItems);

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
            circlemarker: false // 👈 توجه کن: باید با حرف کوچک نوشته بشه: circlemarker نه circleMarker
        }
    });
    map.addControl(drawControl);

    map.on('draw:created', (event) => {
        var layer = event.layer;
        drawnItems.addLayer(layer);

        // استخراج تمام مختصات‌ پلی‌گان‌ها
        var allLayers = drawnItems.getLayers();
        var multiPolygonCoords = [];

        allLayers.forEach(function(layer) {
            if (layer instanceof L.Polygon && !(layer instanceof L.Rectangle)) {
                var latlngs = layer.getLatLngs()[0];
                var coords = latlngs.map(function(latlng) {
                    return [latlng.lng, latlng.lat];
                });

                // بستن حلقه اگر بسته نیست
                var first = coords[0];
                var last = coords[coords.length - 1];
                if (first[0] !== last[0] || first[1] !== last[1]) {
                    coords.push(first);
                }

                multiPolygonCoords.push([coords]);
            }
        });


        // ساخت خروجی به صورت MultiPolygon
        var multiPolygonGeoJSON = {
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

</script>
@endscript
