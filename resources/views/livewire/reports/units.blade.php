<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Unit;
use App\Models\UnitType;
use App\Services\AccessService;
use Illuminate\Support\Facades\Cache;

return new class extends Component
{
    use WithPagination;

    /** Issue #1024 — the inventory is paginated instead of hydrated whole. */
    public int $perPage = 25;

    public bool $showHelpModal = false;

    /** Issue #819 — see the dashboard's `emptyScope` banner for the rationale. */
    public bool $emptyScope = false;

    public string $dateFrom = '';
    public string $dateTo = '';
    public ?int $selectedUnitTypeId = null;
    public ?int $showOnlyNoBoundary = null;

    public function mount(): void
    {
        $this->loadUnitTypes();

        $this->emptyScope = app(AccessService::class)->accessibleUnitIds() === [];
    }

    public function loadUnitTypes(): void
    {
        // UnitType list is static for this report
    }

    /**
     * Issue #1024: a filter change starts a new result set, so the inventory
     * goes back to page 1.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['selectedUnitTypeId', 'showOnlyNoBoundary'], true)) {
            $this->resetPage();
        }
    }

    public function getUnitTypesProperty()
    {
        return Cache::remember('unit_types_all', 86400, fn() => UnitType::all());
    }

    public function chartPayload(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = Unit::query()
            ->whereIn('id', $accessibleIds)
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->when($this->showOnlyNoBoundary !== null, function ($q) {
                if ($this->showOnlyNoBoundary == '1') {
                    $q->whereNull('boundary_id');
                } else {
                    $q->whereNotNull('boundary_id');
                }
            });

        $total = $query->count();

        $hasBoundaryFilter = $this->showOnlyNoBoundary !== null;

        $byType = Unit::query()
            ->selectRaw('COALESCE(unit_types.name, ?) as type_name, COUNT(units.id) as count', ['نامشخص'])
            ->leftJoin('unit_types', 'units.unit_type_id', '=', 'unit_types.id')
            ->whereIn('units.id', $accessibleIds)
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('units.unit_type_id', $this->selectedUnitTypeId))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '1', fn($q) => $q->whereNull('units.boundary_id'))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '0', fn($q) => $q->whereNotNull('units.boundary_id'))
            ->groupBy('type_name')
            ->pluck('count', 'type_name')
            ->toArray();

        $noBoundary = Unit::query()
            ->whereIn('id', $accessibleIds)
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->whereNull('boundary_id')
            ->count();

        $withBoundary = Unit::query()
            ->whereIn('id', $accessibleIds)
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->whereNotNull('boundary_id')
            ->count();

        // Eager loads first: a top-level whereIn() degrades the chain to
        // Query\Builder, on which `with()` is undefined (issue #819).
        // Issue #1024: only the inventory list is paginated — every aggregate
        // above keeps describing the whole filtered set.
        $unitsPage = Unit::query()
            ->with('unitType:id,name', 'region:id,name')
            ->whereIn('id', $accessibleIds)
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '1', fn($q) => $q->whereNull('units.boundary_id'))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '0', fn($q) => $q->whereNotNull('units.boundary_id'))
            ->orderBy('name')
            ->paginate($this->perPage);

        $units = $unitsPage->getCollection()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'type' => $u->unitType?->name ?? '—',
                'region' => $u->region?->name ?? '—',
                'has_boundary' => !is_null($u->boundary_id),
            ])
            ->all();

        return [
            'total' => $total,
            'byType' => $byType,
            'no_boundary' => $noBoundary,
            'with_boundary' => $withBoundary,
            // `units` stays a plain array (the fail-closed security test treats
            // it as one); the paginator rides alongside for the links.
            'units' => $units,
            'unitsPaginator' => $unitsPage,
        ];
    }
}; ?>

<div class="p-6" dir="rtl">
    @php $chart = $this->chartPayload(); @endphp
    {{-- Issue #1042: server-rendered chart aggregates. The @script below reads
        this (morphed on every render) instead of firing a second method request
        per filter change. --}}
    <div id="unitsChartData" class="hidden" data-chart='@json(['byType' => $chart['byType']])'></div>
    <x-header title="گزارش واحدها و مراکز" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="reports" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    @if($emptyScope)
        <div class="alert alert-info mb-6" role="alert">
            <x-icon name="o-information-circle" class="w-5 h-5" />
            <span>واحدی برای نمایش انتخاب نشده</span>
        </div>
    @endif

    {{-- فیلترها --}}
    <x-card shadow class="mb-6">
        <div class="flex gap-3 flex-wrap items-end">
            <div>
                <label class="font-bold text-xs">نوع واحد</label>
                <select class="select select-bordered select-sm" wire:model.live="selectedUnitTypeId">
                    <option value="">همه انواع</option>
                    @foreach($this->unitTypes as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">وضعیت مرز</label>
                <select class="select select-bordered select-sm" wire:model.live="showOnlyNoBoundary">
                    <option value="">همه</option>
                    <option value="1">فاقد مرز</option>
                    <option value="0">دارای مرز</option>
                </select>
            </div>
        </div>
    </x-card>

    {{-- آمار کلی --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">کل واحدها</div>
            <div class="stat-value text-lg text-primary">{{ $chart['total'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">دارای مرز</div>
            <div class="stat-value text-lg text-success">{{ $chart['with_boundary'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs text-error">فاقد مرز</div>
            <div class="stat-value text-lg text-error">{{ $chart['no_boundary'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">نوع واحد</div>
            <div class="stat-value text-lg text-info">{{ count($chart['byType']) }}</div>
        </div>
    </div>

    {{-- نمودار توزیع بر اساس نوع --}}
    <x-card shadow class="mb-6">
        <h3 class="font-bold mb-4">توزیع بر اساس نوع واحد</h3>
        <div id="typeChart" wire:ignore class="h-[300px]"></div>
    </x-card>

    {{-- جدول واحدها --}}
    <x-card shadow>
        <h3 class="font-bold mb-4">لیست واحدها</h3>
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نام</th>
                        <th>نوع</th>
                        <th>منطقه</th>
                        <th>مرز</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($chart['units'] as $u)
                    <tr class="{{ !$u['has_boundary'] ? 'text-error' : '' }}">
                        <td>{{ $u['id'] }}</td>
                        <td>{{ $u['name'] }}</td>
                        <td>{{ $u['type'] }}</td>
                        <td>{{ $u['region'] }}</td>
                        <td>
                            @if($u['has_boundary'])
                                <span class="badge badge-success badge-sm">دارد</span>
                            @else
                                <span class="badge badge-error badge-sm">ندارد</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Issue #1024: the hand-rolled table has no `with-pagination`, so the
            paginator rides alongside the payload. --}}
        @if ($chart['unitsPaginator']->hasPages())
            {{-- `mary-table-pagination` is the app's own pagination style hook
                (`resources/css/app.css`); the hand-rolled table cannot pass it
                through `x-table`, so it is applied by hand. --}}
            <div class="mt-4 mary-table-pagination">
                {{ $chart['unitsPaginator']->links() }}
            </div>
        @endif
    </x-card>
</div>

@assets
<script src="{{ asset('js/chart/highcharts.js') }}"></script>
@endassets

@script
<script>
    function waitForHighcharts(callback, attempts = 0) {
        if (typeof window.Highcharts !== 'undefined') {
            callback();
            return;
        }
        if (attempts > 100) {
            console.error('Highcharts failed to load');
            return;
        }
        setTimeout(() => waitForHighcharts(callback, attempts + 1), 50);
    }

    function destroyChart(id) {
        const container = document.getElementById(id);
        if (!container || typeof Highcharts === 'undefined') return;
        const existing = Highcharts.charts?.find(c => c && c.renderTo === container);
        if (existing) existing.destroy();
        container.innerHTML = '';
    }

    function render() {
        waitForHighcharts(() => {
            // Issue #1042: read the server-rendered aggregates above — the
            // inline @php already ran the payload for this render, so a second
            // method request would just re-run it.
            const el = document.getElementById('unitsChartData');
            const data = el ? JSON.parse(el.dataset.chart) : {};
            destroyChart('typeChart');
            const labels = Object.keys(data.byType || {});
            const values = Object.values(data.byType || {});
            if (labels.length > 0) {
                const colors = ['#6366f1','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#f97316','#ec4899'];
                Highcharts.chart('typeChart', {
                    chart: { type: 'pie' },
                    title: { text: '' },
                    series: [{
                        name: 'تعداد',
                        data: labels.map((l, i) => ({ name: l, y: values[i], color: colors[i % colors.length] }))
                    }],
                    credits: { enabled: false },
                    plotOptions: {
                        pie: { dataLabels: { enabled: true, format: '{point.name}: {y}' } }
                    }
                });
            }
        });
    }

    render();
    // Issue #1042: a filter change now re-renders through the DOM payload
    // (morphed on every server render) instead of a second `chartPayload()`
    // request, so re-render after morph rather than on each $watch.
    Livewire.hook('morphed', ({ component }) => {
        if (component.id === $wire.$id) render();
    });
</script>
@endscript