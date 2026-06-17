<?php

use Livewire\Component;

return new class extends Component {
    public string $map_ip;
    public string $start_point = '';
    public string $end_point = '';

    public function mount(): void
    {
        $this->map_ip = config('map.tile_server_ip', '10.100.252.137');
    }

    public function swapPoints(): void
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
                <input 
                    type="text" 
                    id="start-input" 
                    class="input input-bordered" 
                    placeholder="مبدا (مختصات یا آدرس)" 
                    wire:model="start_point" 
                />
                <input 
                    type="text" 
                    id="end-input" 
                    class="input input-bordered" 
                    placeholder="مقصد (مختصات یا آدرس)" 
                    wire:model="end_point" 
                />
                <x-button 
                    x-on:click="searchRoute()" 
                    class="btn btn-sm btn-primary" 
                    label="محاسبه مسیر" 
                    icon="o-arrow-turn-up-right" 
                />
                <x-button 
                    x-on:click="reverseRoute()" 
                    class="btn btn-sm btn-secondary" 
                    label="معکوس مسیر" 
                    icon="o-arrows-up-down" 
                />
                <x-toggle x-on:click="toggleRoutingContainer()" label="نمایش متنی مسیر"/>
            </div>

            <livewire:maps.map/>
            <div id="route-info" class="bg-base-200 p-4 rounded mt-4">
                <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>
</div>



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

    // متغیرهای سراسری
    var routingControl;
    
    // تنظیم routing control وقتی نقشه آماده است
    waitForMap(function() {
        routingControl = L.Routing.control({
            waypoints: [],
            router: L.Routing.osrmv1({
                serviceUrl: 'http://{{ $map_ip }}:5000/route/v1'
            }),
            routeWhileDragging: true,
            show: true
        }).addTo(window.map);

        // نمایش فاصله و زمان تقریبی
        routingControl.on('routesfound', function (e) {
            var routes = e.routes;
            var summary = routes[0].summary;
            document.getElementById('distance').textContent = (summary.totalDistance / 1000).toFixed(2);
            document.getElementById('duration').textContent = (summary.totalTime / 60).toFixed(0);
        });

        // مخفی کردن پنل مسیریابی
        setTimeout(() => {
            if (routingControl._container) {
                routingControl._container.style.display = 'none';
            }
        }, 100);
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
            var response = await axios.get('http://{{ $map_ip }}:8088/search', {
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
    window.searchRoute = async function() {
        if (!routingControl) {
            console.error('Routing control not initialized');
            return;
        }
        
        var startInput = document.getElementById('start-input').value;
        var endInput = document.getElementById('end-input').value;

        var startPoint = parseCoordinates(startInput) || await geocode(startInput);
        var endPoint = parseCoordinates(endInput) || await geocode(endInput);

        if (startPoint && endPoint) {
            routingControl.setWaypoints([startPoint, endPoint]);
            window.map.fitBounds([startPoint, endPoint]);
        } else {
            alert('لطفاً مبدا و مقصد معتبر وارد کنید');
        }
    };

    // معکوس کردن مسیر
    window.reverseRoute = function() {
        if (!routingControl) return;
        
        var currentWaypoints = routingControl.getWaypoints().slice().reverse();
        
        // بروزرسانی input ها
        var startInput = document.getElementById('start-input');
        var endInput = document.getElementById('end-input');
        var temp = startInput.value;
        startInput.value = endInput.value;
        endInput.value = temp;
        
        // dispatch event به Livewire
        $wire.swapPoints();
        
        routingControl.setWaypoints(currentWaypoints);
    };

    // مخفی/نمایش کردن پنل مسیریابی
    window.toggleRoutingContainer = function() {
        if (!routingControl || !routingControl._container) return;
        
        let container = routingControl._container;
        container.style.display = container.style.display === 'none' ? 'block' : 'none';
    };
</script>
@endscript