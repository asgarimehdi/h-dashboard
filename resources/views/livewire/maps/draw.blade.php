<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $map_ip;
    public string $setview;
    public string $zoom;

    public function mount()
    {
        $this->map_ip = config('map.tile_server_ip', '10.100.252.137');
        $this->setview = '[36.1500, 49.2212]';
        $this->zoom = '12';
    }
};
?>

<link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.css') }}" />
<link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.draw.css') }}" />

<style>
    #map {
        z-index: 0;
    }
    #geojson-output {
        margin-top: 10px;
        padding: 10px;

        border: 1px solid #ddd;
        white-space: pre-wrap;
        height: 200px;
        overflow: auto;
    }
    .dark .leaflet-left,

    .dark .leaflet-layer,

    .dark .leaflet-control-attribution {
        filter: invert(100%) hue-rotate(180deg) brightness(100%) contrast(100%);
    }
</style>

<div>
    <x-header title="رسم مرز" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <div id="map" class="h-180 rounded"></div>
            <h5 class="mt-3">GeoJSON Output:</h5>
            <div id="geojson-output" dir="ltr" class="bg-base-200"></div>
        </div>
    </x-card>
</div>

<script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
<script src="{{ asset('js/leaflet/leaflet.draw.js') }}"></script>
<script src="{{ asset('js/leaflet/leaflet.geometryutil.js') }}"></script>

<script>
    var osm = L.tileLayer('http://{{$map_ip}}:8080/tile/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://zums.ac.ir">Zums</a>'
    });

    var map = L.map('map', {
        center: {{$setview}},
        zoom: {{$zoom}},
        layers: [osm]
    });

    var drawnItems = new L.FeatureGroup();
    map.addLayer(drawnItems);

    var drawControl = new L.Control.Draw({
        edit: {
            featureGroup: drawnItems,
            remove: true
        },
        draw: {
            polygon: true,
            polyline: true,
            rectangle: true,
            circle: true,
            marker: true
        }
    });

    map.addControl(drawControl);

    // رفع خطای Invalid distance value
    L.GeometryUtil.readableDistance = function (distance, isMetric, precision) {
        if (typeof distance !== 'number') {
            distance = Number(distance); // تبدیل مقدار به عدد
        }

        if (isNaN(distance) || distance < 0) {
            console.warn("🚨 مقدار فاصله نامعتبر است:", distance);
            return "نامعتبر";
        }

        return distance.toFixed(precision || 2) + " متر";
    };

    map.on('draw:created', function (event) {
        var layer = event.layer;
        drawnItems.addLayer(layer);

        var geojson = layer.toGeoJSON();
        document.getElementById("geojson-output").textContent = JSON.stringify(geojson, null, 4);

        // رویداد کلیک برای نمایش اطلاعات در Popup
        layer.on('click', function () {
            var popupText = '';

            // 📍 مختصات برای مارکر
            if (layer instanceof L.Marker) {
                var latlng = layer.getLatLng();
                popupText = `📍 مختصات: ${latlng.lat.toFixed(6)}, ${latlng.lng.toFixed(6)}`;

                // 📏 فاصله برای خط (Polyline)
            } else if (layer instanceof L.Polyline && !(layer instanceof L.Polygon)) {
                var latlngs = layer.getLatLngs();
                var distance = 0;

                for (var i = 0; i < latlngs.length - 1; i++) {
                    distance += latlngs[i].distanceTo(latlngs[i + 1]);
                }

                popupText = `📏 فاصله: ${(distance / 1000).toFixed(2)} کیلومتر`;

                // 📐 مساحت برای چندضلعی و مستطیل
            } else if (layer instanceof L.Polygon || layer instanceof L.Rectangle) {
                var latlngs = layer.getLatLngs()[0];
                var area = L.GeometryUtil.geodesicArea(latlngs);

                popupText = (area > 10000)
                    ? `📐 مساحت: ${(area / 10000).toFixed(2)} هکتار`
                    : `📐 مساحت: ${area.toFixed(1)} متر مربع`;

                // 🔵 مساحت و شعاع برای دایره
            } else if (layer instanceof L.Circle) {
                var radius = layer.getRadius();

                // بررسی عددی بودن radius و مقدار معتبر
                if (isNaN(radius) || radius <= 0) {
                    console.warn("🚨 مقدار شعاع نامعتبر است:", radius);
                    radius = 1; // مقدار پیش‌فرض برای جلوگیری از خطا
                }

                var area = Math.PI * Math.pow(radius, 2);
                popupText = `⭕ مساحت دایره: ${(area / 10000).toFixed(2)} هکتار | 🔵 شعاع: ${radius.toFixed(2)} متر`;

            } else {
                popupText = "❓ این شیء قابل اندازه‌گیری نیست.";
            }

            layer.bindPopup(popupText).openPopup();
        });
    });

</script>
