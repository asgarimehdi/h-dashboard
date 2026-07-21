<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\{Ticket, Todo, Unit, Person};
use App\Services\AccessService;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use WithPagination;

    public string $reportType = 'tickets';
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public ?int $unitId = null;
    public ?int $parentUnitId = null;
    public ?int $rootUnitId = null;
    public string $statusFilter = 'all';
    public $units = [];

    public function mount(): void
    {
        $this->dateFrom = Jalalian::fromCarbon(now()->subDays(30))->format('Y/m/d');
        $this->dateTo = Jalalian::fromCarbon(now())->format('Y/m/d');
        $this->units = \App\Models\Unit::all();
    }

    private function parseJalaliDate(?string $date, bool $endOfDay = false): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        try {
            $carbon = Jalalian::fromFormat('Y/m/d', $date)->toCarbon();

            return $endOfDay ? $carbon->endOfDay() : $carbon->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    #[\Livewire\Attributes\Computed]
    public function childUnits()
    {
        if (!$this->rootUnitId) return collect();
        return Unit::where('parent_id', $this->rootUnitId)->get();
    }

    #[\Livewire\Attributes\Computed]
    public function grandChildUnits()
    {
        if (!$this->parentUnitId) return collect();
        return Unit::where('parent_id', $this->parentUnitId)->get();
    }

    public function updatedRootUnitId(): void
    {
        $this->parentUnitId = null;
        $this->unitId = null;
    }

    public function updatedParentUnitId(): void
    {
        $this->unitId = null;
    }

    #[\Livewire\Attributes\Computed]
    public function reportData(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        
        $query = match($this->reportType) {
            'tickets' => Ticket::whereIn('unit_id', $accessibleIds),
            'todos' => Todo::whereIn('unit_id', $accessibleIds),
            'persons' => Person::whereIn('u_id', $accessibleIds),
            default => Ticket::whereIn('unit_id', $accessibleIds),
        };

        // فیلتر تاریخ (ورودی شمسی)
        if ($from = $this->parseJalaliDate($this->dateFrom)) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $this->parseJalaliDate($this->dateTo, endOfDay: true)) {
            $query->where('created_at', '<=', $to);
        }

        // فیلتر واحد (سلسله‌مراتبی)
        $unitId = $this->unitId ?? $this->parentUnitId ?? $this->rootUnitId;
        if ($unitId) {
            $descendantIds = $this->getDescendantIds($unitId);
            $descendantIds[] = $unitId;
            $unitColumn = $this->reportType === 'persons' ? 'u_id' : 'unit_id';
            $query->whereIn($unitColumn, $descendantIds);
        }

        // فیلتر وضعیت
        if ($this->statusFilter !== 'all' && $this->reportType !== 'persons') {
            $query->where('status', $this->statusFilter);
        }

        $total = $query->count();

        $byDay = $query->clone()
            ->selectRaw("date(created_at) as day, count(*) as count")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => Jalalian::fromCarbon(Carbon::parse($row->day))->format('Y/m/d'),
                'count' => (int) $row->count,
            ])
            ->toArray();

        $unitColumn = $this->reportType === 'persons' ? 'u_id' : 'unit_id';
        $byUnit = $query->clone()
            ->selectRaw("$unitColumn, count(*) as count")
            ->groupBy($unitColumn)
            ->with('unit:id,name')
            ->get()
            ->mapWithKeys(fn($item) => [$item->unit?->name ?? 'نامشخص' => $item->count])
            ->toArray();

        $details = [];
        if ($this->reportType === 'tickets') {
            $details = [
                'urgent' => (clone $query)->where('priority', 'urgent')->count(),
                'normal' => (clone $query)->where('priority', 'normal')->count(),
                'low' => (clone $query)->where('priority', 'low')->count(),
                'overdue' => (clone $query)
                    ->whereIn('status', ['created', 'forwarded'])
                    ->where('deadline', '<', now())
                    ->count(),
            ];
        } elseif ($this->reportType === 'persons') {
            $persons = $query->clone()->with(['estekhdam', 'tahsil', 'semat'])->get();
            $details = [
                'byEstekhdam' => $persons->groupBy(fn($p) => $p->estekhdam?->name ?? 'نامشخص')
                    ->map(fn($items) => $items->count())->toArray(),
                'byTahsil' => $persons->groupBy(fn($p) => $p->tahsil?->name ?? 'نامشخص')
                    ->map(fn($items) => $items->count())->toArray(),
                'bySemat' => $persons->groupBy(fn($p) => $p->semat?->name ?? 'نامشخص')
                    ->map(fn($items) => $items->count())->toArray(),
            ];
        }

        return [
            'total' => $total,
            'byDay' => $byDay,
            'byUnit' => $byUnit,
            'details' => $details,
        ];
    }

    private function getDescendantIds(int $unitId): array
    {
        $ids = [];
        $children = Unit::where('parent_id', $unitId)->pluck('id')->toArray();
        foreach ($children as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, $this->getDescendantIds($childId));
        }
        return $ids;
    }

    /**
     * Fresh chart data for JS (wire:navigate + filter updates).
     *
     * @return array{total: int, byDay: array, byUnit: array, details: array}
     */
    public function chartPayload(): array
    {
        return $this->reportData;
    }

}; ?>
    <div>
        <x-header title="گزارش تیکت‌ها" separator progress-indicator>
            <x-slot:actions>
                <x-theme-selector/>
            </x-slot:actions>
        </x-header>

        {{-- فیلترها --}}
        <x-card shadow class="mb-6">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div>
                    <label class="font-bold text-xs">نوع گزارش</label>
                    <select class="select select-bordered select-sm w-full" wire:model.live="reportType">
                        <option value="tickets">تیکت‌ها</option>
                        <option value="todos">وظایف</option>
                        <option value="persons">پرسنل</option>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-xs">واحد اصلی</label>
                    <select class="select select-bordered select-sm w-full" wire:model.live="rootUnitId">
                        <option value="">همه</option>
                        @foreach($units as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="font-bold text-xs">واحد فرعی</label>
                    <select class="select select-bordered select-sm w-full" wire:model.live="parentUnitId">
                        <option value="">همه</option>
                        @foreach($this->childUnits as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="font-bold text-xs">واحد</label>
                    <select class="select select-bordered select-sm w-full" wire:model.live="unitId">
                        <option value="">همه</option>
                        @foreach($this->grandChildUnits as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="font-bold text-xs">وضعیت</label>
                    <select class="select select-bordered select-sm w-full" wire:model.live="statusFilter">
                        <option value="all">همه</option>
                        <option value="created">ایجاد شده</option>
                        <option value="forwarded">ارجاع شده</option>
                        <option value="completed">تکمیل شده</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-3">
                <div class="flex flex-col gap-1" wire:ignore>
                    <label class="font-bold text-xs">از تاریخ</label>
                    <input data-jdp id="advanced_report_date_from" placeholder="از تاریخ"
                        value="{{ $dateFrom }}"
                        class="input input-bordered input-sm w-full text-center cursor-pointer" readonly>
                </div>
                <div class="flex flex-col gap-1" wire:ignore>
                    <label class="font-bold text-xs">تا تاریخ</label>
                    <input data-jdp id="advanced_report_date_to" placeholder="تا تاریخ"
                        value="{{ $dateTo }}"
                        class="input input-bordered input-sm w-full text-center cursor-pointer" readonly>
                </div>
            </div>
        </x-card>

        {{-- آمار --}}
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">کل</div>
                <div class="stat-value text-lg text-primary">{{ $this->reportData['total'] }}</div>
            </div>
            @if($this->reportType === 'tickets' && count($this->reportData['details']) > 0)
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs text-error">فوری</div>
                <div class="stat-value text-lg text-error">{{ $this->reportData['details']['urgent'] }}</div>
            </div>
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs text-warning">عادی</div>
                <div class="stat-value text-lg text-warning">{{ $this->reportData['details']['normal'] }}</div>
            </div>
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs text-error">سررسید گذشته</div>
                <div class="stat-value text-lg text-error">{{ $this->reportData['details']['overdue'] }}</div>
            </div>
            @endif
            @if($this->reportType === 'persons' && count($this->reportData['details']) > 0)
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">تعداد استخدام</div>
                <div class="stat-value text-lg text-info">{{ count($this->reportData['details']['byEstekhdam'] ?? []) }}</div>
            </div>
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">تعداد تحصیلات</div>
                <div class="stat-value text-lg text-success">{{ count($this->reportData['details']['byTahsil'] ?? []) }}</div>
            </div>
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">تعداد سمت</div>
                <div class="stat-value text-lg text-warning">{{ count($this->reportData['details']['bySemat'] ?? []) }}</div>
            </div>
            @endif
        </div>

        {{-- نمودارها --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <x-card shadow>
                <h3 class="font-bold mb-4">روند روزانه</h3>
                <div id="trendChart" wire:ignore style="height: 300px;"></div>
            </x-card>

            <x-card shadow>
                <h3 class="font-bold mb-4">توزیع بر اساس واحد</h3>
                <div id="unitChart" wire:ignore style="height: 300px;"></div>
            </x-card>
        </div>

        {{-- نمودارهای توزیع پرسنل --}}
        @if($reportType === 'persons')
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
            <x-card shadow>
                <h3 class="font-bold mb-4">توزيع بر اساس استخدام</h3>
                <div id="estekhdamChart" wire:ignore style="height: 300px;"></div>
            </x-card>
            <x-card shadow>
                <h3 class="font-bold mb-4">توزيع بر اساس تحصیلات</h3>
                <div id="tahsilChart" wire:ignore style="height: 300px;"></div>
            </x-card>
            <x-card shadow>
                <h3 class="font-bold mb-4">توزيع بر اساس سمت</h3>
                <div id="sematChart" wire:ignore style="height: 300px;"></div>
            </x-card>
        </div>
        @endif
    </div>

    @assets
    <script src="{{ asset('js/chart/highcharts.js') }}"></script>
    <script src="{{ asset('js/chart/treemap.js') }}"></script>
    <script src="{{ asset('js/chart/treegraph.js') }}"></script>
    <script src="{{ asset('js/chart/exporting.js') }}"></script>
    <script src="{{ asset('js/chart/accessibility.js') }}"></script>
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

        function destroyChartById(id) {
            const container = document.getElementById(id);
            if (!container || typeof Highcharts === 'undefined') {
                return;
            }
            const existing = Highcharts.charts?.find(c => c && c.renderTo === container);
            if (existing) {
                existing.destroy();
            }
            container.innerHTML = '';
        }

        async function renderCharts() {
            waitForHighcharts(async () => {
                const reportData = await $wire.chartPayload();

                destroyChartById('trendChart');
                destroyChartById('unitChart');
                destroyChartById('estekhdamChart');
                destroyChartById('tahsilChart');
                destroyChartById('sematChart');

                if (reportData.byDay && reportData.byDay.length > 0) {
                    Highcharts.chart('trendChart', {
                        chart: { type: 'areaspline' },
                        title: { text: '' },
                        xAxis: { categories: reportData.byDay.map(d => d.day) },
                        yAxis: { title: { text: 'تعداد' } },
                        series: [{
                            name: 'تعداد',
                            data: reportData.byDay.map(d => d.count),
                            color: '#6366f1',
                            fillColor: { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, 'rgba(99,102,241,0.3)'], [1, 'rgba(99,102,241,0)']] }
                        }],
                        credits: { enabled: false }
                    });
                }

                const unitLabels = Object.keys(reportData.byUnit || {});
                const unitValues = Object.values(reportData.byUnit || {});
                if (unitLabels.length > 0) {
                    Highcharts.chart('unitChart', {
                        chart: { type: 'pie' },
                        title: { text: '' },
                        series: [{ name: 'تعداد', data: unitLabels.map((label, i) => ({ name: label, y: unitValues[i] })) }],
                        credits: { enabled: false }
                    });
                }

                const details = reportData.details || {};
                const pieColors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316'];

                function renderPieChart(containerId, data) {
                    const labels = Object.keys(data || {});
                    const values = Object.values(data || {});
                    if (labels.length > 0) {
                        Highcharts.chart(containerId, {
                            chart: { type: 'pie' },
                            title: { text: '' },
                            series: [{
                                name: 'تعداد',
                                data: labels.map((label, i) => ({ name: label, y: values[i], color: pieColors[i % pieColors.length] }))
                            }],
                            credits: { enabled: false }
                        });
                    }
                }

                renderPieChart('estekhdamChart', details.byEstekhdam);
                renderPieChart('tahsilChart', details.byTahsil);
                renderPieChart('sematChart', details.bySemat);
            });
        }

        // Full page load + wire:navigate
        renderCharts();

        // Re-render after filter updates
        $wire.$watch('reportType', () => renderCharts());
        $wire.$watch('rootUnitId', () => renderCharts());
        $wire.$watch('parentUnitId', () => renderCharts());
        $wire.$watch('unitId', () => renderCharts());
        $wire.$watch('statusFilter', () => renderCharts());
        $wire.$watch('dateFrom', () => renderCharts());
        $wire.$watch('dateTo', () => renderCharts());

        const initAdvancedReportJdp = () => {
            if (typeof jalaliDatepicker === 'undefined') {
                return;
            }

            jalaliDatepicker.startWatch({
                time: false,
                hasSecond: false,
                format: 'YYYY/MM/DD',
                separatorChars: { date: '/', between: ' ', time: ':' },
            });

            const fromInput = document.getElementById('advanced_report_date_from');
            const toInput = document.getElementById('advanced_report_date_to');

            if (fromInput && !fromInput.dataset.jdpBound) {
                fromInput.dataset.jdpBound = '1';
                fromInput.addEventListener('jdp:change', e => {
                    $wire.set('dateFrom', e.target.value);
                });
            }
            if (toInput && !toInput.dataset.jdpBound) {
                toInput.dataset.jdpBound = '1';
                toInput.addEventListener('jdp:change', e => {
                    $wire.set('dateTo', e.target.value);
                });
            }
        };

        initAdvancedReportJdp();
        document.addEventListener('livewire:navigated', initAdvancedReportJdp);
    </script>
    @endscript
