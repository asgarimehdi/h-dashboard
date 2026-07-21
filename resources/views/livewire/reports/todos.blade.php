<?php

use Livewire\Component;
use App\Models\Todo;
use App\Services\AccessService;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    public string $dateFrom = '';
    public string $dateTo = '';
    public ?int $selectedUnitId = null;
    public ?string $statusFilter = null; // completed, pending, overdue, null=all
    public $units = [];

    public function mount(): void
    {
        $this->dateFrom = Jalalian::fromCarbon(now()->subDays(30))->format('Y/m/d');
        $this->dateTo = Jalalian::fromCarbon(now()->addDays(30))->format('Y/m/d');
        $this->units = \App\Models\Unit::all();
    }

    private function parseJalaliDate(?string $date, bool $endOfDay = false): ?Carbon
    {
        if (empty($date)) return null;
        try {
            $carbon = Jalalian::fromFormat('Y/m/d', $date)->toCarbon();
            return $endOfDay ? $carbon->endOfDay() : $carbon->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function chartPayload(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = Todo::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('unit_id', $accessibleIds))
            ->when($this->selectedUnitId, fn($q) => $q->where('unit_id', $this->selectedUnitId));

        if ($from = $this->parseJalaliDate($this->dateFrom)) {
            $query->where('start_at', '>=', $from);
        }
        if ($to = $this->parseJalaliDate($this->dateTo, endOfDay: true)) {
            $query->where('end_at', '<=', $to);
        }

        $now = now();
        $completed = (clone $query)->where('is_completed', true)->count();
        $pending = (clone $query)->where('is_completed', false)->count();
        $overdue = (clone $query)->where('is_completed', false)
            ->whereNotNull('end_at')->where('end_at', '<', $now)->count();

        $byUnit = (clone $query)
            ->with('unit:id,name')
            ->get()
            ->groupBy(fn($t) => $t->unit?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())
            ->toArray();

        $byDay = (clone $query)
            ->selectRaw("date(start_at) as day, count(*) as count")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn($r) => [
                'day' => Jalalian::fromCarbon(Carbon::parse($r->day))->format('Y/m/d'),
                'count' => (int) $r->count,
            ])
            ->toArray();

        $byStatus = [
            'تکمیل شده' => $completed,
            'در انتظار' => $pending,
            'سررسید گذشته' => $overdue,
        ];

        $items = (clone $query)
            ->when($this->statusFilter === 'completed', fn($q) => $q->where('is_completed', true))
            ->when($this->statusFilter === 'pending', fn($q) => $q->where('is_completed', false)->where(fn($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now)))
            ->when($this->statusFilter === 'overdue', fn($q) => $q->where('is_completed', false)->whereNotNull('end_at')->where('end_at', '<', $now))
            ->with('unit:id,name')
            ->orderBy('start_at', 'desc')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'unit' => $t->unit?->name ?? '—',
                'start' => $t->start_at ? Jalalian::fromCarbon(Carbon::parse($t->start_at))->format('Y/m/d') : '—',
                'end' => $t->end_at ? Jalalian::fromCarbon(Carbon::parse($t->end_at))->format('Y/m/d') : '—',
                'completed' => $t->is_completed,
                'is_overdue' => !$t->is_completed && $t->end_at && $t->end_at < $now,
            ])
            ->toArray();

        return [
            'completed' => $completed,
            'pending' => $pending,
            'overdue' => $overdue,
            'byUnit' => $byUnit,
            'byDay' => $byDay,
            'byStatus' => $byStatus,
            'items' => $items,
        ];
    }

    public function getAccessibleUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return \App\Models\Unit::whereIn('id', $accessibleIds)->get();
    }
}; ?>

<div class="p-6" dir="rtl">
    @php $chart = $this->chartPayload(); @endphp
    <x-header title="گزارش وظایف" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    {{-- فیلترها --}}
    <x-card shadow class="mb-6">
        <div class="flex gap-3 flex-wrap items-end">
            <div>
                <label class="font-bold text-xs">واحد</label>
                <select class="select select-bordered select-sm" wire:model.live="selectedUnitId">
                    <option value="">همه واحدها</option>
                    @foreach($this->accessibleUnits as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">وضعیت</label>
                <select class="select select-bordered select-sm" wire:model.live="statusFilter">
                    <option value="">همه</option>
                    <option value="completed">تکمیل شده</option>
                    <option value="pending">در انتظار</option>
                    <option value="overdue">سررسید گذشته</option>
                </select>
            </div>
            <div class="flex flex-col gap-1" wire:ignore>
                <label class="font-bold text-xs">از تاریخ شروع</label>
                <input data-jdp id="todo_date_from" placeholder="از تاریخ"
                    value="{{ $dateFrom }}"
                    class="input input-bordered input-sm w-40 text-center cursor-pointer" readonly>
            </div>
            <div class="flex flex-col gap-1" wire:ignore>
                <label class="font-bold text-xs">تا تاریخ پایان</label>
                <input data-jdp id="todo_date_to" placeholder="تا تاریخ"
                    value="{{ $dateTo }}"
                    class="input input-bordered input-sm w-40 text-center cursor-pointer" readonly>
            </div>
        </div>
    </x-card>

    {{-- آمار --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">تکمیل شده</div>
            <div class="stat-value text-lg text-success">{{ $chart['completed'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">در انتظار</div>
            <div class="stat-value text-lg text-warning">{{ $chart['pending'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs text-error">سررسید گذشته</div>
            <div class="stat-value text-lg text-error">{{ $chart['overdue'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">نسبت تکمیل</div>
            @php $total = $chart['completed'] + $chart['pending']; @endphp
            <div class="stat-value text-lg text-primary">{{ $total > 0 ? round($chart['completed'] / $total * 100) : 0 }}%</div>
        </div>
    </div>

    {{-- نمودارها --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <x-card shadow>
            <h3 class="font-bold mb-4">توزیع بر اساس وضعیت</h3>
            <div id="statusChart" wire:ignore style="height: 250px;"></div>
        </x-card>
        <x-card shadow>
            <h3 class="font-bold mb-4">توزیع بر اساس واحد</h3>
            <div id="unitChart" wire:ignore style="height: 250px;"></div>
        </x-card>
    </div>

    {{-- جدول --}}
    <x-card shadow>
        <h3 class="font-bold mb-4">لیست وظایف</h3>
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>عنوان</th>
                        <th>واحد</th>
                        <th>شروع</th>
                        <th>پایان</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($chart['items'] as $t)
                    <tr class="{{ $t['is_overdue'] ? 'text-error' : '' }}">
                        <td>{{ $t['id'] }}</td>
                        <td>{{ $t['title'] }}</td>
                        <td>{{ $t['unit'] }}</td>
                        <td>{{ $t['start'] }}</td>
                        <td>{{ $t['end'] }}</td>
                        <td>
                            @if($t['completed'])
                                <span class="badge badge-success badge-sm">تکمیل</span>
                            @elseif($t['is_overdue'])
                                <span class="badge badge-error badge-sm">گذشته</span>
                            @else
                                <span class="badge badge-warning badge-sm">در انتظار</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    @if(empty($chart['items']))
                    <tr>
                        <td colspan="6" class="text-center text-base-content/40">موردی یافت نشد</td>
                    </tr>
                    @endif
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
        if (typeof window.Highcharts !== 'undefined') { callback(); return; }
        if (attempts > 100) { console.error('Highcharts failed to load'); return; }
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

            // Status pie
            destroyChart('statusChart');
            const statusLabels = Object.keys(data.byStatus || {});
            const statusValues = Object.values(data.byStatus || {});
            const colors = ['#10b981', '#f59e0b', '#ef4444'];
            if (statusLabels.length > 0) {
                Highcharts.chart('statusChart', {
                    chart: { type: 'pie' },
                    title: { text: '' },
                    series: [{ name: 'تعداد', data: statusLabels.map((l, i) => ({ name: l, y: statusValues[i], color: colors[i] })) }],
                    credits: { enabled: false },
                    plotOptions: { pie: { dataLabels: { enabled: true, format: '{point.name}: {y}' } } }
                });
            }

            // Unit pie
            destroyChart('unitChart');
            const unitLabels = Object.keys(data.byUnit || {});
            const unitValues = Object.values(data.byUnit || {});
            const pieColors = ['#6366f1','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#f97316','#ec4899'];
            if (unitLabels.length > 0) {
                Highcharts.chart('unitChart', {
                    chart: { type: 'pie' },
                    title: { text: '' },
                    series: [{ name: 'تعداد', data: unitLabels.map((l, i) => ({ name: l, y: unitValues[i], color: pieColors[i % pieColors.length] })) }],
                    credits: { enabled: false },
                    plotOptions: { pie: { dataLabels: { enabled: true, format: '{point.name}: {y}' } } }
                });
            }
        });
    }

    render();
    $wire.$watch('selectedUnitId', () => render());
    $wire.$watch('statusFilter', () => render());
    $wire.$watch('dateFrom', () => render());
    $wire.$watch('dateTo', () => render());

    const initJdp = () => {
        if (typeof jalaliDatepicker === 'undefined') return;
        jalaliDatepicker.startWatch({ time: false, hasSecond: false, format: 'YYYY/MM/DD', separatorChars: { date: '/', between: ' ', time: ':' } });
        const fromInput = document.getElementById('todo_date_from');
        const toInput = document.getElementById('todo_date_to');
        if (fromInput && !fromInput.dataset.jdpBound) {
            fromInput.dataset.jdpBound = '1';
            fromInput.addEventListener('jdp:change', e => $wire.set('dateFrom', e.target.value));
        }
        if (toInput && !toInput.dataset.jdpBound) {
            toInput.dataset.jdpBound = '1';
            toInput.addEventListener('jdp:change', e => $wire.set('dateTo', e.target.value));
        }
    };
    initJdp();
    document.addEventListener('livewire:navigated', initJdp);
</script>
@endscript