<?php

use Livewire\Volt\Component;

new class extends Component {

    public array $counties;

    public function mount()
    {

        $this->counties = [
            "abhar" => asset('geojsons/abhar.geojson'),
            "ijrood" => asset('geojsons/ijrood.geojson'),
            "khodabande" => asset('geojsons/khodabande.geojson'),
            "khorramdare" => asset('geojsons/khorramdare.geojson'),
            "mahneshan" => asset('geojsons/mahneshan.geojson'),
            "soltanie" => asset('geojsons/soltanie.geojson'),
            "tarom" => asset('geojsons/tarom.geojson'),
            "zanjan" => asset('geojsons/zanjan.geojson')
        ];
    }
};
?>


<style>

    .county-menu {

        padding: 5px;

        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 1;
    }

</style>

<div>
    <x-header title="تقسیم بندی شهرستان" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <livewire:maps.map />
            <div class="county-menu bg-base-100/60 rounded-l-box" id="countyMenu" >
                @foreach ($counties as $county => $geojson)
                    <x-toggle label="{{ ucfirst($county) }}"

                              onclick="toggleGeoJson('{{ $county }}')"></x-toggle>
                @endforeach
            </div>
        </div>
    </x-card>
</div>

<script>

    var geojsonLayers = {};

    function toggleGeoJson(county) {
        var counties = @json($counties); // انتقال داده‌های PHP به JavaScript

        if (geojsonLayers[county]) {
            map.removeLayer(geojsonLayers[county]);
            delete geojsonLayers[county];
        } else {
            fetch(counties[county])
                .then(response => response.json())
                .then(data => {
                    var newLayer = L.geoJSON(data, {
                        style: function (feature) {
                            return {
                                color: "orange",
                                weight: 5,
                                opacity: 0.2,
                                fillOpacity: 0.2,


                            };
                        }
                    }).addTo(map);
                    geojsonLayers[county] = newLayer;
                });
        }
    }

</script>
