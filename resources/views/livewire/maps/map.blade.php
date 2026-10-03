<?php

use Livewire\Component;

return new class extends Component {
    public string $map_tile_template;
    public string $setview;
    public string $zoom;

    public function mount(): void
    {
        $this->map_tile_template = config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
        $this->setview = '[36.558188, 48.716125]';
        $this->zoom = '8';
    }
};
?>

{{--
    Issue #028 — this component is the ONLY place allowed to create a Leaflet map.
    Ownership lives in Alpine.store('map') instead of window.map, which is never
    cleared and therefore leaked a detached instance across SPA navigation (host
    pages bound their layers to it, then this component replaced it and wiped
    the layers).

    Two placement rules that cost real debugging time, both verified in a browser:

    1. The x-data element must NOT be the component root. Livewire puts wire:id
       on the OUTERMOST element of a component's markup, so an x-data there is
       claimed by Livewire and its init() never runs — the map silently never
       gets created. Hence the extra <div> wrapper.
    2. $refs.map resolves fine ACROSS the wire:ignore boundary (verified), so
       the container can stay inside it and keep its id="map" + class, which the
       E2E map specs assert on.

    Alpine fires destroy() before init() on SPA navigation, so release() tears
    the old map down before use() builds the new one. The container id and class
    are unchanged — the E2E map specs assert on #map and on clientWidth > 400.
--}}
<div>
    <div x-data="{
            init() {
                $store.map.configure({
                    view: {{ $setview }},
                    zoom: {{ $zoom }},
                    tileUrl: '{{ $map_tile_template }}',
                });

                // Keep the raw instance in a closure local — assigning it to
                // this.map would put it back through Alpine's reactivity
                // (see the note in map-store.js).
                const map = $store.map.use(this.$refs.map);

                if (map) {
                    // Issue (map width): Leaflet captures dimensions at
                    // construction, so a page/layout still settling (SPA
                    // navigation, fonts, hidden containers) can lock in a
                    // smaller width and render half-page.
                    this._invalidate = () => map.invalidateSize();
                    setTimeout(this._invalidate, 100);
                    window.addEventListener('resize', this._invalidate);
                }
            },
            destroy() {
                if (this._invalidate) {
                    window.removeEventListener('resize', this._invalidate);
                }
                $store.map.release(this.$refs.map);
            },
        }">
        <div wire:ignore>
            <div id="map" x-ref="map" class="h-[80lvh] rounded"></div>
        </div>
    </div>
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