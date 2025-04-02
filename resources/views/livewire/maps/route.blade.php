<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $map_ip;
    public string $setview;
    public string $zoom;
    public string $waypoint1;
    public string $waypoint2;

    public function mount()
    {
        $this->map_ip = config('map.tile_server_ip', '10.100.252.137');
        $this->setview = '[36.147694, 49.227870]';
        $this->zoom = '15';
        $this->waypoint1 = '36.149617, 49.217189';
        $this->waypoint2 = '36.146862, 49.229586';
    }

};
?>

<link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.css') }}" />
<link rel="stylesheet" href="{{ asset('css/leaflet/leaflet-routing-machine.css') }}" />


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
    .dark .leaflet-layer,
    .dark .leaflet-control-zoom-in,
    .dark .leaflet-control-zoom-out,
    .dark .leaflet-control-attribution {
        filter: invert(100%) hue-rotate(180deg) brightness(100%) contrast(100%);
    }

</style>
<div>
    <x-header title="محاسبه فاصله جاده‌ای بدون API" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />

        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex">
            <div class="flex-1/2">
                میتوانید نقاط را جابجا کنید
            </div>
            <x-toggle onClick="toggleRoutingContainer()" label="نمایش متنی مسیر" />
        </div>

        <div class="container">
            <div id="map" class="h-180 rounded"></div>
            <div id="route-info" class="bg-base-200">
                 <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>
</div>

<script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
<script src="{{ asset('js/leaflet/leaflet-routing-machine.min.js') }}"></script>


<script>
    var map = L.map('map').setView({{$setview}}, {{$zoom}});

    L.tileLayer('http://{{$map_ip}}:8080/tile/{z}/{x}/{y}.png', {
        attribution: '&copy; Health-Dashboard'
    }).addTo(map);


    routingControl =L.Routing.control({
        waypoints: [
            L.latLng({{$waypoint1}}),
            L.latLng({{$waypoint2}})
        ],
        router: L.Routing.osrmv1({ serviceUrl: 'http://{{$map_ip}}:5000/route/v1' }),
        lineOptions: { styles: [{ color: 'blue', weight: 5 }] },
        routeWhileDragging: true,

        show: true,
    }).addTo(map);
    routingControl.on('routesfound', function (e) {
        let route = e.routes[0];
        let distanceKm = (route.summary.totalDistance / 1000).toFixed(2);
        let durationMin = Math.ceil(route.summary.totalTime / 60);

        document.getElementById('distance').textContent = distanceKm;
        document.getElementById('duration').textContent = durationMin;
    });
    routingControl._container.style.display = 'none';
    function toggleRoutingContainer() {
        let container = routingControl._container;
        if (container.style.display === 'none' || container.style.display === '') {
            container.style.display = 'block';
        } else {
            container.style.display = 'none';
        }
    }
</script>
