<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\Region;
use Livewire\Component;

return new class extends Component
{
    public $location = [];

    public $types = [];
    public $regions = [];

    public array $selectedRegions = [];
    public array $selectedTypes = [];

    public function mount(): void
    {
        $excludedRegionIds = [1];
        $excludedTypeIds   = [1, 2, 3];

        $this->regions = Region::whereNotIn('id', $excludedRegionIds)
            ->select('id', 'name')
            ->get()
            ->toArray();

        $this->types = UnitType::whereNotIn('id', $excludedTypeIds)
            ->select('id', 'name')
            ->get()
            ->toArray();

        $this->fetchLocation();
    }

    public function fetchLocation(): void
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

        $baseUnits = $query
            ->limit(2000)
            ->select([
                'id',
                'name',
                'lat',
                'lng',
                'unit_type_id',
                'parent_id',
            ])
            ->get();

        // Include ancestor units so parent markers exist for connection lines
        $baseIds = $baseUnits->pluck('id')->toArray();
        $ancestorIds = Unit::whereIn('id', $baseIds)
            ->whereNotNull('parent_id')
            ->pluck('parent_id')
            ->toArray();
        $allIds = array_unique(array_merge($baseIds, $ancestorIds));

        $this->location = Unit::whereIn('id', $allIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->select(['id', 'name', 'lat', 'lng', 'unit_type_id', 'parent_id'])
            ->get()
            ->toArray();

        $this->dispatch('locations-updated', locations: $this->location);
    }

    public function updatedSelectedRegions(): void
    {
        $this->fetchLocation();
    }

    public function updatedSelectedTypes(): void
    {
        $this->fetchLocation();
    }
};
?>

<div>
    <x-header title="نقاط لوکیشن" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container relative">
            <div wire:ignore>
                <livewire:maps.map/>
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

@assets
<style>
    .controls-panel {
        padding: 15px;
        background: rgba(255, 255, 255, .6);
        border-radius: 12px;
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 9999;
        width: 270px;
        box-shadow: 0 0 10px rgba(0, 0, 0, .2);
        max-height: 70vh;
        overflow-y: auto;
    }
    .dark .controls-panel {
        filter: invert(100%) hue-rotate(180deg);
    }
</style>
@endassets

@script
<script>
    // تابع کمکی برای چک کردن آماده بودن نقشه
    function waitForMap(callback) {
        var tries = 0;
        function check() {
            if (window.map && typeof window.map.getSize === 'function') {
                callback();
            } else if (++tries > 50) {
                console.error('Map not ready within 10s');
            } else {
                setTimeout(check, 200);
            }
        }
        check();
    }

    // آیکن‌ها بر اساس unit_type_id و نام واحدها
    const typeIcons = {
        4: '/icons/network.svg',      // شبکه بهداشت
        5: '/icons/urban-health.svg',  // مرکز خدمات جامع سلامت شهری
        6: '/icons/urban-rural.svg',   // مرکز خدمات جامع سلامت شهری روستایی
        7: '/icons/rural-health.svg',  // مرکز خدمات جامع سلامت روستایی
        8: '/icons/attached-base.svg',// پایگاه سلامت ضمیمه
        9: '/icons/health-house.svg',  // خانه بهداشت
        10: '/icons/base.svg',         // پایگاه سلامت غیر ضمیمه
        11: '/icons/block.svg',        // بلوک
        12: '/icons/satellite.svg',    // قمر
        13: '/icons/rabies.svg',       // مرکز هاری
        14: '/icons/dental.svg',       // تجمیع دندانپزشکی
        15: '/icons/lab.svg',           // آزمایشگاه
        16: '/icons/school.svg',       // آموزشگاه
        17: '/icons/emergency.svg',    // فوریت
        18: '/icons/worker-house.svg',  // خانه بهداشت کارگری
        19: '/icons/hospital.svg',     // بیمارستان
    };

    const defaultIcon = '/icons/default.svg';

    function getIcon(typeId) {
        return L.icon({
            iconUrl: typeIcons[typeId] ?? defaultIcon,
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32],
        });
    }

    // عمق سلسله‌مراتبی واحد را محاسبه می‌کند
    function getDepth(loc, allLocations) {
        let depth = 0;
        let current = loc;
        while (current && current.parent_id) {
            current = allLocations.find(u => u.id === current.parent_id);
            depth++;
        }
        return depth;
    }

    // رنگ خطوط اتصال بر اساس عمق سلسله‌مراتبی
    const lineColors = ['#14b8a6', '#3b82f6', '#f97316', '#a855f7', '#ef4444'];

    // تنظیم markers layer وقتی نقشه آماده است
    waitForMap(function() {
        window.markersLayer = L.layerGroup().addTo(window.map);
        window.linesLayer = L.layerGroup().addTo(window.map);

        // رندر اولیه مارکرها و خطوط اتصال
        function renderMarkers(locations) {
            if (!window.markersLayer) return;

            window.markersLayer.clearLayers();
            window.linesLayer.clearLayers();

            // رسم خطوط اتصال بین والد و فرزند
            locations.forEach(loc => {
                if (loc.parent_id && loc.lat && loc.lng) {
                    const parent = locations.find(u => u.id === loc.parent_id);
                    if (parent && parent.lat && parent.lng) {
                        const depth = getDepth(parent, locations);
                        const color = lineColors[Math.min(depth, lineColors.length - 1)];
                        L.polyline(
                            [[loc.lat, loc.lng], [parent.lat, parent.lng]],
                            { color, weight: 2, opacity: 0.7, dashArray: '6 4' }
                        ).addTo(window.linesLayer);
                    }
                }
            });

            // رسم مارکرها
            locations.forEach(loc => {
                L.marker(
                    [loc.lat, loc.lng],
                    { icon: getIcon(loc.unit_type_id) }
                )
                    .bindPopup(loc.name)
                    .addTo(window.markersLayer);
            });
        }

        // رندر مارکرهای اولیه
        var initialLocations = {{ Js::from($location) }};
        renderMarkers(initialLocations);

        // گوش دادن به event بروزرسانی
        Livewire.on('locations-updated', ({ locations }) => {
            renderMarkers(locations);
        });
    });
</script>
@endscript