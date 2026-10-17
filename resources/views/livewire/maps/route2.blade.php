<?php

use Livewire\Component;

return new class extends Component
{
    public string $map_tile_template;

    public string $routing_url;

    public string $geocoding_url;

    public string $start_point = '';

    public string $end_point = '';

    public function mount(): void
    {
        $this->map_tile_template = config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
        $this->routing_url = config('map.routing_url', 'http://127.0.0.1:5000');
        $this->geocoding_url = config('map.geocoding_url', 'http://127.0.0.1:8088');
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
        <div class="relative">
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
                    x-on:click="window.searchRoute()"
                    class="btn btn-sm btn-primary"
                    label="محاسبه مسیر"
                    icon="o-arrow-turn-up-right"
                />
                <x-button
                    x-on:click="window.reverseRoute()"
                    class="btn btn-sm btn-secondary"
                    label="معکوس مسیر"
                    icon="o-arrows-up-down"
                />
                <x-toggle x-on:click="window.toggleRoutingContainer()" label="نمایش متنی مسیر"/>
            </div>

            <livewire:maps.map/>
            <div id="route-info" class="bg-base-200 p-4 rounded mt-4">
                <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>
</div>

@push('scripts')
    {{-- Issue #984: routing is this page's only consumer, so it ships here
         instead of in the global layout. `@stack('scripts')` runs before
         Livewire's deferred `start()` evaluates the `@script` block below, so
         `L.Routing` exists by the time `onReady()` calls `initRouting()`. --}}
    <script src="{{ asset('js/leaflet/leaflet-routing-machine.min.js') }}"></script>
@endpush

@script
<script>
    var routingControl;

    function parseCoordinates(input) {
        if (!input) return null;
        var coords = input.split(',').map(Number);
        if (coords.length === 2 && !isNaN(coords[0]) && !isNaN(coords[1])) {
            return L.latLng(coords[0], coords[1]);
        }
        return null;
    }

    /**
     * Build the Nominatim search URL with the query encoded by URLSearchParams.
     *
     * Hand-written `'?q=' + query` is how a library swap breaks silently: an
     * address containing `&` or `#` would truncate the query, axios used to
     * encode it for us.
     */
    function geocodeSearchUrl(base, query) {
        var url = new URL(base + '/search');
        url.searchParams.set('q', query);
        url.searchParams.set('format', 'json');
        url.searchParams.set('limit', '1');
        return url.toString();
    }

    /**
     * Nominatim answers with an array of `{lat, lon}`. An error body (object or
     * string) is not an array, and `[].length > 0` on a non-array is
     * `undefined > 0` — false, but only by accident. Be explicit.
     */
    function parseGeocodeResponse(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return null;
        }

        var result = data[0];
        if (result === null || typeof result !== 'object') {
            return null;
        }

        var lat = Number(result.lat);
        var lon = Number(result.lon);
        if (!isFinite(lat) || !isFinite(lon)) {
            return null;
        }

        return L.latLng(lat, lon);
    }

    async function geocode(query) {
        if (!query) return null;
        try {
            var response = await fetch(geocodeSearchUrl('{{ $geocoding_url }}', query), {
                headers: { 'Accept': 'application/json' }
            });
            // fetch, unlike axios, resolves on 4xx/5xx — check it ourselves.
            if (!response.ok) {
                console.error('Geocoding error:', response.status, response.statusText);
                return null;
            }
            return parseGeocodeResponse(await response.json());
        } catch (error) {
            console.error('Geocoding error:', error);
        }
        return null;
    }

    window.Alpine.store('map').onReady(function (map) {
    function initRouting() {
        if (typeof L.Routing === 'undefined' || typeof L.Routing.control !== 'function') {
            console.error('L.Routing not available. Make sure leaflet-routing-machine.js is loaded.');
            return;
        }

        routingControl = L.Routing.control({
            waypoints: [],
            router: L.Routing.osrmv1({
                serviceUrl: '{{ $routing_url }}/route/v1'
            }),
            routeWhileDragging: true,
            show: true
        }).addTo(map);

        routingControl.on('routesfound', function (e) {
            var summary = e.routes[0].summary;
            document.getElementById('distance').textContent = (summary.totalDistance / 1000).toFixed(2);
            document.getElementById('duration').textContent = Math.ceil(summary.totalTime / 60);
        });

        routingControl.on('routingerror', function (e) {
            console.error('Routing error:', e.error);
        });

        setTimeout(() => {
            if (routingControl._container) {
                routingControl._container.style.display = 'none';
            }
        }, 200);

        window.routingControl = routingControl;
    }

    initRouting();

    // Issue #028 — these three are bound to `window` because the Blade
    // x-on:click handlers call them as window.searchRoute() etc. They close over
    // the live `map` handed to us by onReady(), so they can never act on a map
    // from a previous page.
    window.searchRoute = async function() {
        if (!routingControl) {
            console.error('Routing control not initialized');
            return;
        }
        var startPoint = parseCoordinates(document.getElementById('start-input').value) || await geocode(document.getElementById('start-input').value);
        var endPoint = parseCoordinates(document.getElementById('end-input').value) || await geocode(document.getElementById('end-input').value);
        if (startPoint && endPoint) {
            routingControl.setWaypoints([startPoint, endPoint]);
            map.fitBounds([startPoint, endPoint]);
        } else {
            alert('لطفاً مبدا و مقصد معتبر وارد کنید');
        }
    };

    window.reverseRoute = function() {
        if (!routingControl) return;
        var currentWaypoints = routingControl.getWaypoints().slice().reverse();
        var startInput = document.getElementById('start-input');
        var endInput = document.getElementById('end-input');
        var temp = startInput.value;
        startInput.value = endInput.value;
        endInput.value = temp;
        routingControl.setWaypoints(currentWaypoints);
    };

    window.toggleRoutingContainer = function() {
        if (!routingControl || !routingControl._container) return;
        var container = routingControl._container;
        container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
    };
});
</script>
@endscript
