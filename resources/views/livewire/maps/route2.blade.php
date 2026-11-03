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
            <div class="flex items-center gap-2 flex-wrap pb-3" id="route2-toolbar">
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
                {{-- #1352 CSP-compat: buttons wrapped in [data-action] spans —
                     resources/js/map-pages.js delegates toolbar clicks. The old
                     x-on:click="window.searchRoute()" global calls cannot run
                     under the CSP build. --}}
                <span data-action="search-route">
                    <x-button
                        class="btn btn-sm btn-primary"
                        label="محاسبه مسیر"
                        icon="o-arrow-turn-up-right"
                    />
                </span>
                <span data-action="reverse-route">
                    <x-button
                        class="btn btn-sm btn-secondary"
                        label="معکوس مسیر"
                        icon="o-arrows-up-down"
                    />
                </span>
                <span data-action="toggle-routing">
                    <x-toggle label="نمایش متنی مسیر"/>
                </span>
            </div>

            <livewire:maps.map wire:key="route2-page-map" />
            <div id="route-info" class="bg-base-200 p-4 rounded mt-4">
                <strong>📏 فاصله جاده‌ای:</strong> <span id="distance">---</span> کیلومتر<br>
                <strong>⌛ زمان تقریبی سفر:</strong> <span id="duration">---</span> دقیقه
            </div>
        </div>
    </x-card>

    {{-- #1352 CSP-compat: routing/geocoding config published in an inert JSON
         block for resources/js/map-pages.js. The old script block cannot
         execute under the CSP build at all. --}}
    <script type="application/json" nonce="{{ Vite::cspNonce() }}" id="route2-data">@json(['routingUrl' => $routing_url, 'geocodingUrl' => $geocoding_url])</script>
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
