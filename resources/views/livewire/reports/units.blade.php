<?php

use Livewire\Component;
use App\Models\Unit;
use App\Models\UnitType;
use App\Services\AccessService;

return new class extends Component
{
    public string $dateFrom = '';
    public string $dateTo = '';
    public ?int $selectedUnitTypeId = null;
    public ?int $showOnlyNoBoundary = null;

    public function mount(): void
    {
        $this->loadUnitTypes();
    }

    public function loadUnitTypes(): void
    {
        // UnitType list is static for this report
    }

    public function getUnitTypesProperty()
    {
        return UnitType::all();
    }

    public function chartPayload(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = Unit::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('id', $accessibleIds))
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->when($this->showOnlyNoBoundary !== null && $this->showOnlyNoBoundary !== '', function ($q) {
                if ($this->showOnlyNoBoundary == '1') {
                    $q->whereNull('boundary_id');
                } else {
                    $q->whereNotNull('boundary_id');
                }
            });

        $total = $query->count();

        $hasBoundaryFilter = $this->showOnlyNoBoundary !== null && $this->showOnlyNoBoundary !== '';

        $byType = Unit::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('id', $accessibleIds))
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '1', fn($q) => $q->whereNull('boundary_id'))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '0', fn($q) => $q->whereNotNull('boundary_id'))
            ->with('unitType:id,name')
            ->get()
            ->groupBy(fn($u) => $u->unitType?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())
            ->toArray();

        $noBoundary = Unit::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('id', $accessibleIds))
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->whereNull('boundary_id')
            ->count();

        $withBoundary = Unit::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('id', $accessibleIds))
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->whereNotNull('boundary_id')
            ->count();

        $units = Unit::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('id', $accessibleIds))
            ->when($this->selectedUnitTypeId, fn($q) => $q->where('unit_type_id', $this->selectedUnitTypeId))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '1', fn($q) => $q->whereNull('boundary_id'))
            ->when($hasBoundaryFilter && $this->showOnlyNoBoundary == '0', fn($q) => $q->whereNotNull('boundary_id'))
            ->with('unitType:id,name', 'region:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'type' => $u->unitType?->name ?? '—',
                'region' => $u->region?->name ?? '—',
                'has_boundary' => !is_null($u->boundary_id),
            ])
            ->toArray();

        return [
            'total' => $total,
            'byType' => $byType,
            'no_boundary' => $noBoundary,
            'with_boundary' => $withBoundary,
            'units' => $units,
        ];
    }
}; ?>

<div class="p-6" dir="rtl">
    @php $chart = $this->chartPayload(); @endphp
    <x-header title="گزارش واحدها و مراکز" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

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
        <div id="typeChart" wire:ignore style="height: 300px;"></div>
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

    async function render() {
        waitForHighcharts(async () => {
            const data = await $wire.chartPayload();
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
    $wire.$watch('selectedUnitTypeId', () => render());
    $wire.$watch('showOnlyNoBoundary', () => render());
</script>
@endscript