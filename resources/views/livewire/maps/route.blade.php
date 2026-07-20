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
    // Destroy any existing routing control to avoid duplicates on re-navigation
    if (window.routingControl) {
        window.map.removeControl(window.routingControl);
        delete window.routingControl;
    }

    // Guard: only init if map exists and has no stale container
    if (!window.map || typeof window.map.getSize !== 'function') {
        console.warn('Map not ready, retrying...');
        setTimeout(() => {
            if (window.map && typeof window.map.getSize === 'function') {
                initRouting();
            }
        }, 200);
        return;
    }
    initRouting();

    function initRouting() {
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
            document.getElementById('distance').textContent = (route.summary.totalDistance / 1000).toFixed(2);
            document.getElementById('duration').textContent = Math.ceil(route.summary.totalTime / 60);
        });

        setTimeout(() => {
            if (routingControl._container) {
                routingControl._container.style.display = 'none';
            }
        }, 200);

        window.routingControl = routingControl;
    }

    window.toggleRoutingContainer = function() {
        if (!window.routingControl || !window.routingControl._container) return;
        let container = window.routingControl._container;
        container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
    };
</script>
@endscript