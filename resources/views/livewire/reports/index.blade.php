<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\{Ticket, Todo, ActivityLog, User, Unit, Person};
use App\Services\AccessService;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use WithPagination;

    public string $reportType = 'tickets';
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public ?int $selectedUnitId = null;
    public $units = [];

    public function mount(): void
    {
        $this->dateFrom = Jalalian::fromCarbon(now()->subDays(30))->format('Y/m/d');
        $this->dateTo = Jalalian::fromCarbon(now())->format('Y/m/d');
        $this->units = Unit::all();
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
    public function reportData(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $query = match($this->reportType) {
            'tickets' => Ticket::whereIn('unit_id', $accessibleIds),
            'todos' => Todo::whereIn('unit_id', $accessibleIds),
            'users' => User::query(),
            'persons' => Person::whereIn('u_id', $accessibleIds),
            default => Ticket::whereIn('unit_id', $accessibleIds),
        };

        if ($from = $this->parseJalaliDate($this->dateFrom)) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $this->parseJalaliDate($this->dateTo, endOfDay: true)) {
            $query->where('created_at', '<=', $to);
        }
        if ($this->selectedUnitId) {
            $unitColumn = $this->reportType === 'persons' ? 'u_id' : 'unit_id';
            $query->where($unitColumn, $this->selectedUnitId);
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

        $byUnit = $query->clone()
            ->when($this->reportType === 'tickets' || $this->reportType === 'todos', fn($q) => $q->select('unit_id')->groupBy('unit_id'))
            ->when($this->reportType === 'tickets' || $this->reportType === 'todos', fn($q) => $q->with('unit'))
            ->when($this->reportType === 'persons', fn($q) => $q->select('u_id')->groupBy('u_id')->with('unit:id,name'))
            ->get()
            ->groupBy(fn($item) => $item->unit?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())
            ->toArray();

        $details = [];
        if ($this->reportType === 'persons') {
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

    public function getUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return Unit::whereIn('id', $accessibleIds)->get();
    }

    /**
     * Fresh chart data for JS (wire:navigate + filter updates).
     *
     * @return array{total: int, byDay: array<int, array{day: string, count: int}>, byUnit: array<string, int>}
     */
    public function chartPayload(): array
    {
        return $this->reportData;
    }

}; ?>

    <div class="p-6" dir="rtl">
        <x-header title="گزارش‌ها" separator progress-indicator>
            <x-slot:actions>
                <x-theme-selector/>
            </x-slot:actions>
        </x-header>

        {{-- فیلترها --}}
        <x-card shadow class="mb-6">
            <div class="flex gap-3 flex-wrap items-end">
                <div>
                    <label class="font-bold text-xs">نوع گزارش</label>
                    <select class="select select-bordered select-sm" wire:model.live="reportType">
                        <option value="tickets">تیکت‌ها</option>
                        <option value="todos">وظایف</option>
                        <option value="users">کاربران</option>
                        <option value="persons">پرسنل</option>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-xs">واحد</label>
                    <select class="select select-bordered select-sm" wire:model.live="selectedUnitId">
                        <option value="">همه واحدها</option>
                        @foreach($units as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1" wire:ignore>
                    <label class="font-bold text-xs">از تاریخ</label>
                    <input data-jdp id="report_date_from" placeholder="از تاریخ"
                        value="{{ $dateFrom }}"
                        class="input input-bordered input-sm w-40 text-center cursor-pointer" readonly>
                </div>
                <div class="flex flex-col gap-1" wire:ignore>
                    <label class="font-bold text-xs">تا تاریخ</label>
                    <input data-jdp id="report_date_to" placeholder="تا تاریخ"
                        value="{{ $dateTo }}"
                        class="input input-bordered input-sm w-40 text-center cursor-pointer" readonly>
                </div>
            </div>
        </x-card>

        {{-- خلاصه --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">کل</div>
                <div class="stat-value text-lg text-primary">{{ $this->reportData['total'] }}</div>
            </div>
        </div>

        {{-- نمودار روند --}}
        <x-card shadow class="mb-6">
            <h3 class="font-bold mb-4">روند روزانه</h3>
            <div id="reportChart" wire:ignore style="height: 300px;"></div>
        </x-card>

        {{-- توزیع بر اساس واحد --}}
        <x-card shadow>
            <h3 class="font-bold mb-4">توزیع بر اساس واحد</h3>
            <div id="unitChart" wire:ignore style="height: 300px;"></div>
        </x-card>

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

                destroyChartById('reportChart');
                destroyChartById('unitChart');
                destroyChartById('estekhdamChart');
                destroyChartById('tahsilChart');
                destroyChartById('sematChart');

                if (reportData.byDay && reportData.byDay.length > 0) {
                    Highcharts.chart('reportChart', {
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

        // Re-render after filter updates (livewire morph)
        $wire.$watch('reportType', () => renderCharts());
        $wire.$watch('selectedUnitId', () => renderCharts());
        $wire.$watch('dateFrom', () => renderCharts());
        $wire.$watch('dateTo', () => renderCharts());

        const initReportJdp = () => {
            if (typeof jalaliDatepicker === 'undefined') {
                return;
            }

            jalaliDatepicker.startWatch({
                time: false,
                hasSecond: false,
                format: 'YYYY/MM/DD',
                separatorChars: { date: '/', between: ' ', time: ':' },
            });

            const fromInput = document.getElementById('report_date_from');
            const toInput = document.getElementById('report_date_to');

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

        initReportJdp();
        document.addEventListener('livewire:navigated', initReportJdp);
    </script>
    @endscript
