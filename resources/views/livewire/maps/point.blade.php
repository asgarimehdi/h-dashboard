<?php

use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitType;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Livewire\Component;

return new class extends Component
{
    public $location = [];

    public $types = [];

    public $regions = [];

    public function mount(): void
    {
        $excludedRegionIds = [1];
        $excludedTypeIds = [1, 2, 3];

        $cache = app(CacheInvalidationServiceInterface::class);

        $this->regions = $cache->remember('maps', 'global', function () use ($excludedRegionIds) {
            return Region::whereNotIn('id', $excludedRegionIds)
                ->select('id', 'name')
                ->get()
                ->toArray();
        }, 5, ['segment' => 'point_map:regions']);

        $this->types = $cache->remember('maps', 'global', function () use ($excludedTypeIds) {
            return UnitType::whereNotIn('id', $excludedTypeIds)
                ->select('id', 'name')
                ->get()
                ->toArray();
        }, 5, ['segment' => 'point_map:types']);

        $this->fetchLocation();
    }

    /**
     * Load every accessible located unit once. Region/type filtering happens
     * client-side from this payload (filtering used to round-trip through
     * Livewire and re-send + re-render the whole set on every toggle —
     * ~2s per switch, issue #776).
     */
    public function fetchLocation(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $baseUnits = Unit::query()
            ->whereIn('id', $accessibleIds)  // Organizational Scope: only accessible units + descendants
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->limit(2000)
            ->select([
                'id',
                'name',
                'lat',
                'lng',
                'unit_type_id',
                'parent_id',
                'region_id',
            ])
            ->get();

        // Include ancestor units so parent markers exist for connection lines (single JOIN instead of 2 queries)
        $baseIds = $baseUnits->pluck('id')->toArray();
        $allIds = array_unique(array_merge($baseIds, Unit::ancestorIds($baseIds)->toArray()));

        $this->location = Unit::whereIn('id', $allIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->select(['id', 'name', 'lat', 'lng', 'unit_type_id', 'parent_id', 'region_id'])
            ->get()
            ->toArray();
    }
};
?>

<div data-point-map>
    <x-header title="نقاط لوکیشن" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="relative">
            <div wire:ignore>
                <livewire:maps.map wire:key="point-page-map" />
            </div>

            <div class="controls-panel space-y-4">
                <div data-filter-group="regions">
                    <label class="font-bold block mb-2">انتخاب شهرستان</label>
                    @foreach ($regions as $region)
                        <x-toggle
                            value="{{ $region['id'] }}"
                            label="{{ $region['name'] }}"
                        />
                    @endforeach
                </div>

                <div data-filter-group="types">
                    <label class="font-bold block mb-2">انتخاب نوع</label>
                    @foreach ($types as $type)
                        <x-toggle
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
<style nonce="{{ Vite::cspNonce() }}">
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

@assets
    {{-- Issue #1182: Leaflet core is page-specific now and ships on the HOST page
         — never inside `maps/map.blade.php`, whose own asset would arrive with the
         child component, after this page's Alpine `init()` had already asked the
         store for a map. `@assets`, not `@push('scripts')`: a pushed tag lands at
         the end of the body, and on `wire:navigate` Livewire morphs the body and
         runs Alpine BEFORE evaluating those scripts — measured `L is not defined`
         and a blank map (#028). Livewire injects `@assets` into <head> and
         evaluates new head assets before the navigation completes. --}}
    <script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
@endassets

