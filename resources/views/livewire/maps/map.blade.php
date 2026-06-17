<?php

use Livewire\Component;

return new class extends Component {
    public string $map_ip;
    public string $setview;
    public string $zoom;

    public function mount(): void
    {
        $this->map_ip = config('map.tile_server_ip', '10.100.252.137');
        $this->setview = '[36.558188, 48.716125]';
        $this->zoom = '8';
    }
};
?>

<div wire:ignore>
    <div id="map" class="h-[80lvh] rounded"></div>
</div>

@assets
<style>
    #map {
        z-index: 0;
    }

    .dark .leaflet-layer,
    .dark .leaflet-control-zoom-in,
    .dark .leaflet-control-zoom-out,
    .dark .leaflet-control-attribution {
        filter: invert(100%) hue-rotate(180deg) brightness(100%) contrast(100%);
    }
</style>
@endassets

@script
<script>
    var map = L.map('map').setView({{ $setview }}, {{ $zoom }});
    
    L.tileLayer('http://{{ $map_ip }}:8080/tile/{z}/{x}/{y}.png', {
        attribution: '&copy; Health-Dashboard',
        className: 'map-tiles'
    }).addTo(map);
    
    // ذخیره map در window برای دسترسی از کامپوننت‌های دیگر
    window.map = map;
</script>
@endscript