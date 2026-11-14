<?php

use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitType;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Livewire\Attributes\Computed;
use Livewire\Component;

return new class extends Component
{
    /** Marker rows the bounded base query may return at most. */
    private const MAX_MARKERS = 2000;

    /**
     * The marker set is deliberately NOT a public property (#1481).
     *
     * This page has no Livewire actions of its own and #776 already moved
     * region/type filtering client-side, so a public `$location` was never
     * re-sent per toggle — but any future interaction would have dehydrated
     * and re-hydrated the whole set (~100 kB on the dev database) on every
     * round-trip. The payload belongs to the map layer, so it is loaded into
     * this non-public holder and published exactly once, as an inert JSON
     * block (the `county` shape, #1173).
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $locationsData = null;

    /** Located units in scope, before the bound. */
    private int $locatedTotal = 0;

    /** Base rows actually published — parent context rows are excluded. */
    private int $baseShown = 0;

    /**
     * Toggle sources ({id, name}) — O(types/regions), cached, and public on
     * purpose: they are small and drive the filter loops in the template.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $types = [];

    /** @var array<int, array<string, mixed>> */
    public array $regions = [];

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
    }

    /**
     * The published marker payload plus its truncation metadata.
     *
     * Computed, so the array never travels in a Livewire snapshot. Blade calls
     * this twice (the inert block and the truncation indicator); computed
     * values are cached per request, so that is one load, not two.
     *
     * @return array{locations: array<int, array<string, mixed>>, shown: int, total: int, truncated: bool}
     */
    #[Computed]
    public function mapData(): array
    {
        if ($this->locationsData === null) {
            $this->loadLocations();
        }

        return [
            'locations' => $this->locationsData ?? [],
            'shown' => $this->baseShown,
            'total' => $this->locatedTotal,
            'truncated' => $this->baseShown < $this->locatedTotal,
        ];
    }

    /**
     * Load the located marker set once: a deterministic, disclosed slice of the
     * accessible units plus the ancestors needed to keep their connection lines.
     *
     * Region/type filtering happens client-side from this payload (#776), so the
     * bound — not a per-toggle round-trip — is what keeps the page finite.
     */
    private function loadLocations(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $located = fn () => Unit::query()
            ->whereIn('id', $accessibleIds)  // Organizational Scope: only accessible units + descendants
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        // #1481: the old `.limit(2000)` carried no ORDER BY, so a different
        // subset could surface between query plans. `id` is stable and
        // index-backed (name order would be collation-dependent), and the
        // count below turns the bound into something the page can disclose
        // instead of dropping markers silently. `whereIn('id', [])` compiles
        // to `0 = 1`, so an empty scope stays closed — never guard it.
        $this->locatedTotal = $located()->count();

        $baseUnits = $located()
            ->orderBy('id')
            ->limit(self::MAX_MARKERS)
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

        $this->baseShown = min($this->locatedTotal, self::MAX_MARKERS);

        // Include ancestor units so parent markers exist for connection lines
        // (single JOIN instead of 2 queries). No second limit: this set is
        // bounded by the capped base set and every row is a direct parent the
        // renderer needs for its line — a limit here would drop lines silently.
        $baseIds = $baseUnits->pluck('id')->toArray();
        $allIds = array_unique(array_merge($baseIds, Unit::ancestorIds($baseIds)->toArray()));

        $this->locationsData = Unit::query()
            ->whereIn('id', $allIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
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
        @if ($this->mapData['truncated'])
            {{-- #1481: the bound is disclosed, not silent. Kept outside
                 `.controls-panel` because that panel is color-inverted in
                 dark mode. --}}
            <div data-point-truncation role="status" class="px-4 py-2 text-sm">
                نمایش نخستین {{ number_format($this->mapData['shown']) }} از {{ number_format($this->mapData['total']) }} واحد دارای موقعیت
            </div>
        @endif

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

    {{-- #1481: the marker set is published ONCE, as an inert JSON block (the
         `county` shape, #1173). It never executes, so no CSP exception is
         needed, and it is not HTML-attribute-escaped the way a Livewire
         snapshot is. The JSON_* flags matter: `@json`'s default leaves
         Persian names as `\uXXXX` (6 bytes per character), which measured
         144,137 B against 100,873 B on the dev payload. `county` can use a
         bare `@json` because its GeoJSON is ASCII; this block must not. --}}
    <script type="application/json" nonce="{{ Vite::cspNonce() }}" id="point-locations-data">@json($this->mapData['locations'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
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

