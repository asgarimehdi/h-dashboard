<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\Region;
use Livewire\Volt\Component;

new class extends Component
{
    public $location = [];

    public $types = [];
    public $regions = [];

    public array $selectedRegions = [];
    public array $selectedTypes = [];

    public function mount()
    {
        // آیتم‌های حذف‌شده
        $excludedRegionIds = [1];     // سطح استان
        $excludedTypeIds   = [1,2,3]; // وزارت، دانشگاه، معاونت

        $this->regions = Region::whereNotIn('id', $excludedRegionIds)
            ->select('id','name')
            ->get()
            ->toArray();

        $this->types = UnitType::whereNotIn('id', $excludedTypeIds)
            ->select('id','name')
            ->get()
            ->toArray();

        $this->fetchLocation();
    }

    public function fetchLocation()
    {
        $query = Unit::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        if ($this->selectedRegions) {
            $query->whereIn('region_id', $this->selectedRegions);
        }

        if ($this->selectedTypes) {
            $query->whereIn('unit_type_id', $this->selectedTypes);
        }

        $this->location = $query
            ->limit(2000)
            ->select([
                'id',
                'name',
                'lat',
                'lng',
                'unit_type_id',
            ])
            ->get()
            ->toArray();

        $this->dispatch('locations-updated', locations: $this->location);
    }

    public function updatedSelectedRegions() { $this->fetchLocation(); }
    public function updatedSelectedTypes()   { $this->fetchLocation(); }
};
?>

<div>
    <style>
        .controls-panel {
            padding: 15px;
            background: rgba(255,255,255,.6);
            border-radius: 12px;
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 9999;
            width: 270px;
            box-shadow: 0 0 10px rgba(0,0,0,.2);
            max-height: 70vh;
            overflow-y: auto;
        }
        .dark .controls-panel {
            filter: invert(100%) hue-rotate(180deg);
        }
    </style>

    <x-header title="نقاط لوکیشن" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container relative">
            <div wire:ignore>
                <livewire:maps.map />
            </div>

            <div class="controls-panel space-y-4">
                <div>
                    <label class="font-bold block mb-2">انتخاب شهرستان</label>
                    @foreach ($regions as $region)
                        <x-toggle
                            wire:model.live="selectedRegions"
                            value="{{ $region['id'] }}"
                            label="{{ $region['name'] }}"
                        />
                    @endforeach
                </div>

                <div>
                    <label class="font-bold block mb-2">انتخاب نوع</label>
                    @foreach ($types as $type)
                        <x-toggle
                            wire:model.live="selectedTypes"
                            value="{{ $type['id'] }}"
                            label="{{ $type['name'] }}"
                        />
                    @endforeach
                </div>
            </div>
        </div>
    </x-card>
</div>

@script
<script>
    let markersLayer = L.layerGroup().addTo(map);

    // ===== آیکن‌ها بر اساس unit_type_id =====
    const typeIcons = {
        4: '/icons/4.png',
        5: '/icons/5.png',
        6: '/icons/6.png',
        7: '/icons/7.png',
        8: '/icons/8.png',
        9: '/icons/9.png',
        10: '/icons/10.png',
        11: '/icons/11.png',
        12: '/icons/12.png',
        13: '/icons/13.png',
        14: '/icons/14.png',
        15: '/icons/15.png',
        16: '/icons/16.png',
        17: '/icons/17.png',
        18: '/icons/18.png',
        19: '/icons/19.png',
    };

    const defaultIcon = '/icons/default.png';

    function getIcon(typeId) {
        return L.icon({
            iconUrl: typeIcons[typeId] ?? defaultIcon,
            iconSize: [10, 20],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32],
        });
    }

    function renderMarkers(locations) {
        markersLayer.clearLayers();

        locations.forEach(loc => {
            L.marker(
                [loc.lat, loc.lng],
                { icon: getIcon(loc.unit_type_id) }
            )
                .bindPopup(loc.name)
                .addTo(markersLayer);
        });
    }

    renderMarkers(@json($this->location));

    Livewire.on('locations-updated', ({ locations }) => {
        renderMarkers(locations);
    });
</script>
@endscript
