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

    public function swapPoints()
    {
        $temp = $this->start_point;
        $this->start_point = $this->end_point;
        $this->end_point = $temp;
    }
};
?>



<div>
    <x-header title="محاسبه فاصله جاده‌ای" separator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <div class="flex items-center gap-2 flex-wrap pb-3">
                <x-input type="text" id="start-input" class="x-input" placeholder="مبدا (مختصات یا آدرس)" wire:model.live="start_point" />
                <x-input type="text" id="end-input" class="x-input" placeholder="مقصد (مختصات یا آدرس)" wire:model.live="end_point" />
                <x-button onclick="searchRoute()" class="btn btn-sm btn-primary" label="محاسبه مسیر" icon="o-arrow-turn-up-right" />
                <x-button onclick="reverseRoute()" class="btn btn-sm btn-secondary" label="معکوس مسیر" icon="o-arrows-up-down" />
                <x-toggle onClick="toggleRoutingContainer()" label="نمایش متنی مسیر" />
            </div>

            <div id="map" class="h-180 rounded" wire:ignore></div>
            <div id="route-info">
                {{$this->start_point}}
                <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>
</div>

<script>
    // تنظیم اولیه نقشه
    var map = L.map('map').setView({{$this->setview}}, {{$this->zoom}});
    L.tileLayer('http://{{$this->map_ip}}:8080/tile/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // کنترل مسیریابی
    var routingControl = L.Routing.control({
        waypoints: [],
        router: L.Routing.osrmv1({serviceUrl: 'http://{{$this->map_ip}}:5000/route/v1'}),
        routeWhileDragging: true,
        show: true
    }).addTo(map);

    // نمایش فاصله و زمان تقریبی
    routingControl.on('routesfound', function (e) {
        var routes = e.routes;
        var summary = routes[0].summary;
        document.getElementById('distance').textContent = (summary.totalDistance / 1000).toFixed(2);
        document.getElementById('duration').textContent = (summary.totalTime / 60).toFixed(0);
    });

    // تبدیل متن به مختصات
    function parseCoordinates(input) {
        if (!input) return null;
        var coords = input.split(',').map(Number);
        if (coords.length === 2 && !isNaN(coords[0]) && !isNaN(coords[1])) {
            return L.latLng(coords[0], coords[1]);
        }
        return null;
    }

    // جستجوی آدرس با nominatim
    async function geocode(query) {
        if (!query) return null;
        try {
            var response = await axios.get('http://{{$this->map_ip}}:8088/search', {
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

    // تابع جستجوی مسیر
    async function searchRoute() {
        {{--var startInput = {{$start_point}};--}}
        {{--var endInput = {{$end_point}};--}}
        var startInput = document.getElementById('start-input').value;
        var endInput = document.getElementById('end-input').value;

        var startPoint = parseCoordinates(startInput) || await geocode(startInput);
        var endPoint = parseCoordinates(endInput) || await geocode(endInput);

        if (startPoint && endPoint) {
            routingControl.setWaypoints([startPoint, endPoint]);
            map.fitBounds([startPoint, endPoint]);
        } else {
            alert('لطفاً مبدا و مقصد معتبر وارد کنید');
        }
    }

    // معکوس کردن مسیر
    function reverseRoute() {
        var currentWaypoints = routingControl.getWaypoints().slice().reverse();
        Livewire.dispatch('swap-points');
        routingControl.setWaypoints(currentWaypoints);
    }

    // مخفی/نمایش کردن پنل مسیریابی
    routingControl._container.style.display = 'none';
    function toggleRoutingContainer() {
        let container = routingControl._container;
        container.style.display = container.style.display === 'none' ? 'block' : 'none';
    }
</script>
