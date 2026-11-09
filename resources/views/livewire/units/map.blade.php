<?php

use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component {
    use Toast;

    #[Locked]
    public int $unitId;
    public ?array $unit = null;
    public ?string $geojson = null;
    public bool $hasBoundary = false;

    public function mount(int $id): void
    {
        $this->unitId = $id;
        $this->loadUnit();
    }

    /**
     * Issue #817: the org tree IS the authorization model — boundary reads
     * and writes must prove the unit sits inside the caller's reachable set.
     */
    private function assertUnitInScope(int $unitId): bool
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($unitId, $accessibleIds, true)) {
            $this->error('شما مجاز به مشاهده یا ویرایش این واحد نیستید.');

            return false;
        }

        return true;
    }

    public function loadUnit(): void
    {
        // #817: unitId is a mount parameter but stays client-settable between
        // calls — #[Locked] alone only rejects tampering, it does not hide
        // other units. The explicit scope check is the fix.
        if (! $this->assertUnitInScope($this->unitId)) {
            $this->unit = null;
            $this->hasBoundary = false;
            $this->geojson = null;

            return;
        }

        // #1023: project ST_AsGeoJSON in the query. A LEFT join (not inner):
        // a unit with no boundary must still load — that is the draw path, and
        // an inner join would make it disappear. Reading the relation's appended
        // attribute used to fire the accessor twice for this one unit.
        /** @var (Unit&object{geojson: string|null})|null $unit */
        $unit = Unit::query()
            ->leftJoin('boundaries', 'units.boundary_id', '=', 'boundaries.id')
            ->select([
                'units.*',
                DB::raw('ST_AsGeoJSON(boundaries.boundary) as geojson'),
            ])
            ->find($this->unitId);

        if (! $unit) {
            $this->error('واحد یافت نشد.');
            return;
        }

        // Note: `$unit->toArray()` no longer carries a `boundary` key (the
        // relation is not loaded); the bare geometry now arrives as `geojson`.
        $this->unit = $unit->toArray();
        $this->hasBoundary = $unit->boundary_id !== null;
        $this->geojson = $unit->geojson;
    }

    public function saveBoundary($geojsonData): void
    {
        // #817: scope-check before touching the boundary rows.
        if (! $this->assertUnitInScope($this->unitId)) {
            return;
        }

        $feature = json_decode($geojsonData, true);

        if (! isset($feature['geometry']['type']) || ! in_array($feature['geometry']['type'], ['Polygon', 'MultiPolygon'])) {
            $this->error('فقط نوع‌های Polygon یا MultiPolygon پشتیبانی می‌شوند.');
            return;
        }

        $geometry = json_encode($feature['geometry']);

        $unit = Unit::find($this->unitId);
        if (! $unit) return;

        if ($unit->boundary_id) {
            DB::table('boundaries')
                ->where('id', $unit->boundary_id)
                ->update([
                    'boundary' => DB::raw("ST_GeomFromGeoJSON(" . DB::getPdo()->quote($geometry) . ")"),
                ]);
        } else {
            $boundaryId = DB::table('boundaries')->insertGetId([
                'boundary' => DB::raw("ST_GeomFromGeoJSON(" . DB::getPdo()->quote($geometry) . ")"),
            ]);
            $unit->update(['boundary_id' => $boundaryId]);
        }

        $this->success('مرز با موفقیت ذخیره شد.');
        $this->loadUnit();
        $this->dispatch('boundaryUpdated');
    }

    public function deleteBoundary(): void
    {
        // #817: scope-check before nulling the FK or deleting geometry.
        if (! $this->assertUnitInScope($this->unitId)) {
            return;
        }

        $unit = Unit::find($this->unitId);

        if (! $unit || ! $unit->boundary_id) {
            return;
        }

        $boundaryId = $unit->boundary_id;

        // #702: nulling boundary_id alone left the geometry row orphaned in
        // `boundaries` forever.
        //
        // ORDER MATTERS: units.boundary_id is ON DELETE CASCADE, so deleting the
        // `boundaries` row first would cascade-delete THIS unit (and any child
        // units). Null the FK first so the boundary row is unreferenced, then
        // remove it.
        try {
            DB::transaction(function () use ($unit, $boundaryId): void {
                $unit->update(['boundary_id' => null]);
                DB::table('boundaries')->where('id', $boundaryId)->delete();
            });
        } catch (\Throwable $e) {
            report($e);
            $this->error('حذف مرز ناموفق بود.');

            return;
        }

        $this->success('مرز حذف شد.');
        $this->loadUnit();
        $this->dispatch('boundaryUpdated');
    }
}; ?>

<div>
    <script type="application/json" nonce="{{ Vite::cspNonce() }}" id="unit-map-data">@json(['tileTemplate' => config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'), 'geojson' => $geojson])</script>
    <x-header title="نقشه: {{ $unit['name'] ?? '' }}" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex items-center gap-3 mb-4">
            <a href="/units" class="btn btn-ghost btn-sm">
                <x-icon name="o-arrow-left" class="w-5 h-5"/>
                بازگشت
            </a>
            @if($hasBoundary)
                <span class="badge badge-success badge-sm">مرز تعریف شده</span>
            @else
                <span class="badge badge-warning badge-sm">بدون مرز</span>
            @endif
        </div>

        <div wire:ignore>
            <div id="unitMap" class="h-[70vh] rounded-lg"></div>
        </div>

        <div class="flex justify-end gap-2 mt-4">
            {{-- #817: out-of-scope mounts render no actionable controls. --}}
            @if($unit)
            <button type="button" class="btn btn-primary" id="saveMapBoundaryBtn">
                <x-icon name="o-check" class="w-5 h-5"/>
                ذخیره
            </button>
            @if($hasBoundary)
                <x-button label="حذف مرز" icon="o-trash" class="btn-error" wire:click="deleteBoundary"
                          wire:confirm="آیا از حذف مرز مطمئن هستید؟"/>
            @endif
            @endif
        </div>
    </x-card>
</div>

@assets
<style nonce="{{ Vite::cspNonce() }}">
    #unitMap { z-index: 0; }
    .dark .leaflet-layer,
    .dark .leaflet-control-zoom-in,
    .dark .leaflet-control-zoom-out,
    .dark .leaflet-control-attribution {
        filter: invert(100%) hue-rotate(180deg) brightness(100%) contrast(100%);
    }
</style>
@endassets

@push('styles')
    {{-- Issue #984 (CSS half, #1018): `units/map` is the only page that draws
         boundaries, so the draw control's stylesheet ships here rather than in
         the global layout — pushed into `<head>` via `@stack('styles')`. --}}
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.draw.css') }}" />
@endpush

@assets
    {{-- Issue #984 / #1182: `units/map` is the only page that draws boundaries,
         so the draw plugin ships here instead of in the global layout, with
         Leaflet core ahead of it. Both go through `@assets`, i.e. into <head>, so
         `L` and `L.Control.Draw` exist when `initUnitMap()` runs — a pushed tag
         at the end of the body would arrive after Alpine on `wire:navigate`
         (#028). --}}
    <script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('js/leaflet/leaflet.draw.js') }}"></script>
@endassets

