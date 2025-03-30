<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $map_ip;
    public string $setview;
    public string $zoom;

    public function mount()
    {
        $this->map_ip = config('map.tile_server_ip', '10.100.252.137');
        $this->setview = '[36.558188, 48.716125]';
        $this->zoom = '8';
    }
};
?>

<link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.css') }}" />
<style>
    #map { height: 600px; }
    .county-menu {

        padding: 10px;
        border-radius: 5px;
        box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 1000;
    }
    .county-menu button {
        display: block;
        margin: 5px;
        padding: 5px 10px;
        cursor: pointer;
        background: #007bff;
        color: white;
        border: none;
        border-radius: 3px;
    }
    .county-menu button:hover { background: #0056b3; }
</style>

<div>
    <x-header title="تقسیم بندی شهرستان" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="container">
            <div id="map"></div>
            <div class="county-menu" id="countyMenu"></div>
        </div>
    </x-card>
</div>

<script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
<script>
    var map = L.map('map').setView({{$setview}}, {{$zoom}});
    L.tileLayer('http://{{$map_ip}}:8080/tile/{z}/{x}/{y}.png', {
        attribution: '&copy; Health-Dashboard'
    }).addTo(map);

    var geojsonLayers = {};
    var selectedLayer = null;
    var counties = {
        "abhar": "{{ asset('geojsons/abhar.geojson') }}",
        "ijrood": "{{ asset('geojsons/ijrood.geojson') }}",
        "khodabande": "{{ asset('geojsons/khodabande.geojson') }}",
        "khorramdare": "{{ asset('geojsons/khorramdare.geojson') }}",
        "mahneshan": "{{ asset('geojsons/mahneshan.geojson') }}",
        "soltanie": "{{ asset('geojsons/soltanie.geojson') }}",
        "tarom": "{{ asset('geojsons/tarom.geojson') }}",
        "zanjan": "{{ asset('geojsons/zanjan.geojson') }}"
    };

    var menuDiv = document.getElementById("countyMenu");
    Object.keys(counties).forEach(function (county) {
        var btn = document.createElement("button");
        btn.innerHTML = county;
        btn.onclick = function () { toggleGeoJson(county); };
        menuDiv.appendChild(btn);
    });

    function toggleGeoJson(county) {
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
                                color: "blue",
                                weight: 2,
                                opacity: 0.5,
                                fillOpacity: 0.3
                            };
                        }
                    }).addTo(map);
                    geojsonLayers[county] = newLayer;
                });
        }
    }
</script>
