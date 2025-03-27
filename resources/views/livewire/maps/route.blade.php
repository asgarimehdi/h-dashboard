<link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.css') }}" />
<link rel="stylesheet" href="{{ asset('css/leaflet/leaflet-routing-machine.css') }}" />


<style>
    #map {
        height: 600px;
    }
    #route-info {
        margin-top: 10px;
        padding: 10px;

        text-align: right;
        direction: rtl;
    }
</style>
<div>
    <x-header title="محاسبه فاصله جاده‌ای بدون API" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <div id="map"></div>
            <div id="route-info">
                <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>
</div>

<script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
<script src="{{ asset('js/leaflet/leaflet-routing-machine.min.js') }}"></script>


<script>
    var map = L.map('map').setView([36.1500, 49.2212], 12); // تهران

    // اضافه کردن نقشه OpenStreetMap
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let markers = [];
    let routingControl = null;

    // کلیک روی نقشه برای اضافه کردن مارکر
    map.on('click', function (e) {
        if (markers.length >= 2) {
            markers.forEach(m => map.removeLayer(m));
            markers = [];
            if (routingControl) map.removeControl(routingControl);
        }

        let marker = L.marker(e.latlng).addTo(map);
        markers.push(marker);

        if (markers.length === 2) {
            drawRoute();
        }
    });

    // تابع رسم مسیر بین دو نقطه
    function drawRoute() {
        let start = markers[0].getLatLng();
        let end = markers[1].getLatLng();

        routingControl = L.Routing.control({
            waypoints: [start, end],
            router: L.Routing.osrmv1({ serviceUrl: 'https://router.project-osrm.org/route/v1' }),
            lineOptions: { styles: [{ color: 'blue', weight: 5 }] },
            createMarker: function () { return null; } // جلوگیری از اضافه‌شدن مارکرهای اضافی
        }).addTo(map);

        routingControl.on('routesfound', function (e) {
            let route = e.routes[0];
            let distanceKm = (route.summary.totalDistance / 1000).toFixed(2);
            let durationMin = Math.ceil(route.summary.totalTime / 60);

            document.getElementById('distance').textContent = distanceKm;
            document.getElementById('duration').textContent = durationMin;
        });
    }
</script>
