<?php

use Livewire\Component;

return new class extends Component {
    public string $map_tile_template;
    public string $routing_url;
    public string $waypoint1;
    public string $waypoint2;

    public function mount(): void
    {
        $this->map_tile_template = config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
        $this->routing_url = config('map.routing_url', 'http://127.0.0.1:5000');
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
            {{-- #1352 CSP-compat: the toggle is wrapped in [data-route-toggle]
                 — resources/js/map-pages.js binds its change event. The old
                 x-on:click="toggleRoutingContainer()" global call cannot run
                 under the CSP build. --}}
            <div data-route-toggle>
                <x-toggle label="نمایش متنی مسیر" />
            </div>
        </div>

        <div class="relative">
            <livewire:maps.map wire:key="route-page-map" />
            <div id="route-info" class="bg-base-200 p-4 rounded mt-4">
                <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>

    {{-- #1352 CSP-compat: routing config published in an inert JSON block
         for resources/js/map-pages.js. The old @script block cannot execute
         under the CSP build at all. --}}
    <script type="application/json" nonce="{{ Vite::cspNonce() }}" id="route-data">@json(['waypoint1' => $waypoint1, 'waypoint2' => $waypoint2, 'routingUrl' => $routing_url])</script>
</div>



@push('styles')
    {{-- Issue #984 (CSS half, #1018): the routing stylesheet ships on this page
         alone — pushed into `<head>` via `@stack('styles')`, not the global
         layout. --}}
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet-routing-machine.css') }}" />
@endpush

@assets
    {{-- Issue #984 / #1182: Leaflet core and routing are this page's only
         consumers, so they ship here instead of in the global layout. `@assets`
         keeps them in <head>: a `@push('scripts')` tag at the end of the body is
         evaluated after Alpine has initialised the map on `wire:navigate`
         (`L is not defined`, blank map — #028), while Livewire evaluates new head
         assets before the swap completes. `L` and `L.Routing` are therefore
         defined when `onReady()` runs. Leaflet comes first: routing-machine
         patches `L`. --}}
    <script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('js/leaflet/leaflet-routing-machine.min.js') }}"></script>
@endassets