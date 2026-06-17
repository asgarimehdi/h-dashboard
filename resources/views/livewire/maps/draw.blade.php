<?php

use Livewire\Component;

return new class extends Component {
    //
};
?>

<div>
    <x-header title="رسم مرز" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <livewire:maps.map/>
            <div class="bg-base-200 p-4 rounded mt-4">
                <h5 class="mt-3 font-bold mb-2">خروجی:</h5>
                <div id="geojson-output" dir="ltr"></div>
            </div>
        </div>
    </x-card>
</div>

@assets


<style>
    #map {
        max-height: 400px;
    }
    #geojson-output {
        margin-top: 10px;
        padding: 10px;
        border: 1px solid #ddd;
        overflow: scroll;
        max-height: 300px;
        background-color: #f5f5f5;
        font-family: monospace;
        font-size: 14px;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
</style>
@endassets

@script
<script>
    // تابع کمکی برای چک کردن آماده بودن نقشه
    function waitForMap(callback) {
        if (window.map && typeof window.map.getSize === 'function') {
            callback();
        } else {
            setTimeout(() => waitForMap(callback), 100);
        }
    }

    // تنظیم ابزارهای رسم وقتی نقشه آماده است
    waitForMap(function() {
        var drawnItems = new L.FeatureGroup();
        window.map.addLayer(drawnItems);

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

        window.map.addControl(drawControl);

        // رفع خطای Invalid distance value
        if (L.GeometryUtil) {
            L.GeometryUtil.readableDistance = function (distance, isMetric, precision) {
                if (typeof distance !== 'number') {
                    distance = Number(distance);
                }

                if (isNaN(distance) || distance < 0) {
                    console.warn("🚨 مقدار فاصله نامعتبر است:", distance);
                    return "نامعتبر";
                }

                return distance.toFixed(precision || 2) + " متر";
            };
        }

        window.map.on('draw:created', function (event) {
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

                    if (isNaN(radius) || radius <= 0) {
                        console.warn("🚨 مقدار شعاع نامعتبر است:", radius);
                        radius = 1;
                    }

                    var area = Math.PI * Math.pow(radius, 2);
                    popupText = `⭕ مساحت دایره: ${(area / 10000).toFixed(2)} هکتار | 🔵 شعاع: ${radius.toFixed(2)} متر`;

                } else {
                    popupText = "❓ این شیء قابل اندازه‌گیری نیست.";
                }

                layer.bindPopup(popupText).openPopup();
            });
        });
    });
</script>
@endscript