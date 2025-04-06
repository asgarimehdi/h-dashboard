<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $map_ip;
    public string $setview;
    public string $zoom;
    public string $start_point = '';
    public string $end_point = '';

    public function mount()
    {
        $this->map_ip = config('map.tile_server_ip', '10.100.252.137');
        $this->setview = '[36.1500, 49.2212]';
        $this->zoom = '12';
    }
};
?>



<style>
    #map {
        z-index: 0;
    }
    #route-info {
        margin-top: 10px;
        padding: 10px;
        text-align: right;
        direction: rtl;
    }
    .search-container {

    }
    .search-input {


    }
    .dark .leaflet-layer,
    .dark .leaflet-control-zoom-in,
    .dark .leaflet-control-zoom-out,
    .dark .leaflet-control-attribution {
        filter: invert(100%) hue-rotate(180deg) brightness(100%) contrast(100%);
    }
</style>


<div>
    <x-header title="محاسبه فاصله جاده‌ای" separator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <div class="search-container row flex-1 pb-3">
                <input type="text" id="start-input" class="search-input flex-1/3" placeholder="مبدا (مختصات یا آدرس)" wire:model="start_point">
                <input type="text" id="end-input" class="search-input flex-1/3" placeholder="مقصد (مختصات یا آدرس)" wire:model="end_point">
                <button onclick="searchRoute()" class="btn btn-primary flex-1/3">محاسبه مسیر</button>
            </div>

            <div id="map" class="h-180 rounded"></div>
            <div id="route-info">
                <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>
</div>



<script>

    var map = L.map('map').setView({{$setview}}, {{$zoom}});
    L.tileLayer('http://{{$map_ip}}:8080/tile/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Routing control
    var routingControl = L.Routing.control({
        waypoints: [],
        router: L.Routing.osrmv1({serviceUrl: 'http://{{$map_ip}}:5000/route/v1'}),
        routeWhileDragging: true,
        show: true
    }).addTo(map);

    // Handle route results
    routingControl.on('routesfound', function(e) {
        var routes = e.routes;
        var summary = routes[0].summary;
        document.getElementById('distance').textContent = (summary.totalDistance / 1000).toFixed(2);
        document.getElementById('duration').textContent = (summary.totalTime / 60).toFixed(0);
    });

    // Simple coordinate parser
    function parseCoordinates(input) {
        var coords = input.split(',').map(Number);
        if (coords.length === 2 && !isNaN(coords[0]) && !isNaN(coords[1])) {
            return L.latLng(coords[0], coords[1]);
        }
        return null;
    }

    // Search using Nominatim (OpenStreetMap's search engine)
    async function geocode(query) {
        try {
            var response = await axios.get('http://{{$map_ip}}:8088/search', {
                params: {
                    q: query,
                    format: 'json',
                    limit: 1
                }
            });

            if (response.data.length > 0) {
                var result = response.data[0];
                return L.latLng(result.lat, result.lon);
            }
            return null;
        } catch (error) {
            console.error('Geocoding error:', error);
            return null;
        }
    }

    // Main route search function
    async function searchRoute() {
        var startInput = document.getElementById('start-input').value;
        var endInput = document.getElementById('end-input').value;

        // Try to parse as coordinates first
        var startPoint = parseCoordinates(startInput) || await geocode(startInput);
        var endPoint = parseCoordinates(endInput) || await geocode(endInput);

        if (startPoint && endPoint) {
            routingControl.setWaypoints([startPoint, endPoint]);
            map.fitBounds([startPoint, endPoint]);
        } else {
            alert('لطفاً مبدا و مقصد معتبر وارد کنید');
        }
    }

</script>

