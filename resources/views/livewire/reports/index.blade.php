<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\{Ticket, Todo, ActivityLog, User, Unit};
use App\Services\AccessService;

return new class extends Component
{
    use WithPagination;

    public string $reportType = 'tickets';
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public ?int $selectedUnitId = null;
    public array $units = [];

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(30)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        $this->units = Unit::all();
    }

    public function getReportDataProperty(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $query = match($this->reportType) {
            'tickets' => Ticket::whereIn('unit_id', $accessibleIds),
            'todos' => Todo::whereIn('unit_id', $accessibleIds),
            'users' => User::query(),
            default => Ticket::whereIn('unit_id', $accessibleIds),
        };

        if ($this->dateFrom) $query->where('created_at', '>=', $this->dateFrom);
        if ($this->dateTo) $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');
        if ($this->selectedUnitId) $query->where('unit_id', $this->selectedUnitId);

        $total = $query->count();

        $byDay = $query->clone()
            ->selectRaw("date(created_at) as day, count(*) as count")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->toArray();

        $byUnit = $query->clone()
            ->when($this->reportType === 'tickets' || $this->reportType === 'todos', fn($q) => $q->select('unit_id')->groupBy('unit_id'))
            ->when($this->reportType === 'tickets' || $this->reportType === 'todos', fn($q) => $q->with('unit'))
            ->get()
            ->groupBy(fn($item) => $item->unit?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())
            ->toArray();

        return [
            'total' => $total,
            'byDay' => $byDay,
            'byUnit' => $byUnit,
        ];
    }

    public function getUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return Unit::whereIn('id', $accessibleIds)->get();
    }

}; ?>

    <div class="p-6" dir="rtl">
        <h1 class="text-2xl font-bold mb-6 flex items-center gap-2">
            <x-icon name="o-chart-bar" class="w-7 h-7 text-primary" />
            گزارش‌ها
        </h1>

        {{-- فیلترها --}}
        <x-card shadow class="mb-6">
            <div class="flex gap-3 flex-wrap items-end">
                <div>
                    <label class="font-bold text-xs">نوع گزارش</label>
                    <select class="select select-bordered select-sm" wire:model="reportType">
                        <option value="tickets">تیکت‌ها</option>
                        <option value="todos">وظایف</option>
                        <option value="users">کاربران</option>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-xs">واحد</label>
                    <select class="select select-bordered select-sm" wire:model="selectedUnitId">
                        <option value="">همه واحدها</option>
                        @foreach($units as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-input label="از تاریخ" type="date" wire:model.live="dateFrom" class="w-40" />
                <x-input label="تا تاریخ" type="date" wire:model.live="dateTo" class="w-40" />
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
            <div id="reportChart" style="height: 300px;"></div>
        </x-card>

        {{-- توزیع بر اساس واحد --}}
        <x-card shadow>
            <h3 class="font-bold mb-4">توزیع بر اساس واحد</h3>
            <div id="unitChart" style="height: 300px;"></div>
        </x-card>
    </div>

    @script
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const reportData = @json($this->reportData);

            // نمودار روند
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

            // نمودار واحدها
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
        });
    </script>
    @endscript
