<?php

use Livewire\Component;

return new class extends Component {
    public string $map_ip;
    public string $waypoint1;
    public string $waypoint2;

    public function mount(): void
    {
        $this->map_ip = config('map.tile_server_ip', '10.100.252.137');
        $this->waypoint1 = '36.149617, 49.217189';
        $this->waypoint2 = '36.146862, 49.229586';
    }
};
?>

<div>
    <x-header title="محاسبه فاصله جاده‌ای بدون API" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <div class="flex-1">
                می‌توانید نقاط را جابجا کنید
            </div>
            <x-toggle x-on:click="toggleRoutingContainer()" label="نمایش متنی مسیر"/>
        </div>

        <div class="container">
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

    // تنظیم routing control وقتی نقشه آماده است
    waitForMap(function() {
        var routingControl = L.Routing.control({
            waypoints: [
                L.latLng({{ $waypoint1 }}),
                L.latLng({{ $waypoint2 }})
            ],
            router: L.Routing.osrmv1({ 
                serviceUrl: 'http://{{ $map_ip }}:5000/route/v1' 
            }),
            lineOptions: { 
                styles: [{ color: 'blue', weight: 5 }] 
            },
            routeWhileDragging: true,
            show: true,
        }).addTo(window.map);
        
        routingControl.on('routesfound', function (e) {
            let route = e.routes[0];
            let distanceKm = (route.summary.totalDistance / 1000).toFixed(2);
            let durationMin = Math.ceil(route.summary.totalTime / 60);

            document.getElementById('distance').textContent = distanceKm;
            document.getElementById('duration').textContent = durationMin;
        });
        
        // مخفی کردن container پیش‌فرض routing
        setTimeout(() => {
            if (routingControl._container) {
                routingControl._container.style.display = 'none';
            }
        }, 100);
        
        // ذخیره در window برای دسترسی از toggle
        window.routingControl = routingControl;
    });
    
    window.toggleRoutingContainer = function() {
        if (!window.routingControl || !window.routingControl._container) return;
        
        let container = window.routingControl._container;
        if (container.style.display === 'none' || container.style.display === '') {
            container.style.display = 'block';
        } else {
            container.style.display = 'none';
        }
    };
</script>
@endscript