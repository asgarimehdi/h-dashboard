<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\{Ticket, Todo, Unit};
use App\Services\AccessService;

new #[Layout('components.layouts.app', ['title' => 'گزارش پیشرفته'])]
class extends Component
{
    use WithPagination;

    public string $reportType = 'tickets';
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public ?int $unitId = null;
    public ?int $parentUnitId = null;
    public ?int $rootUnitId = null;
    public string $statusFilter = 'all';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(30)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function getUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return Unit::whereIn('id', $accessibleIds)->whereNull('parent_id')->get();
    }

    public function getChildUnitsProperty()
    {
        if (!$this->rootUnitId) return collect();
        return Unit::where('parent_id', $this->rootUnitId)->get();
    }

    public function getGrandChildUnitsProperty()
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

    public function getReportDataProperty(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        
        $query = match($this->reportType) {
            'tickets' => Ticket::whereIn('unit_id', $accessibleIds),
            'todos' => Todo::whereIn('unit_id', $accessibleIds),
            default => Ticket::whereIn('unit_id', $accessibleIds),
        };

        // فیلتر تاریخ
        if ($this->dateFrom) $query->where('created_at', '>=', $this->dateFrom);
        if ($this->dateTo) $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');

        // فیلتر واحد (سلسله‌مراتبی)
        $unitId = $this->unitId ?? $this->parentUnitId ?? $this->rootUnitId;
        if ($unitId) {
            // شامل فرزندان هم می‌شه
            $descendantIds = $this->getDescendantIds($unitId);
            $descendantIds[] = $unitId;
            $query->whereIn('unit_id', $descendantIds);
        }

        // فیلتر وضعیت
        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $total = $query->count();

        // روند روزانه
        $byDay = $query->clone()
            ->selectRaw("date(created_at) as day, count(*) as count")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->toArray();

        // توزیع بر اساس واحد
        $byUnit = $query->clone()
            ->selectRaw('unit_id, count(*) as count')
            ->groupBy('unit_id')
            ->with('unit:id,name')
            ->get()
            ->mapWithKeys(fn($item) => [$item->unit?->name ?? 'نامشخص' => $item->count])
            ->toArray();

        // آمار تفصیلی (برای تیکت‌ها)
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

    public function render()
    {
        return view('livewire.reports.advanced');
    }
};
    <div class="p-6" dir="rtl">
        <h1 class="text-2xl font-bold mb-6 flex items-center gap-2">
            <x-icon name="o-chart-bar" class="w-7 h-7 text-primary" />
            گزارش پیشرفته
        </h1>

        {{-- فیلترها --}}
        <x-card shadow class="mb-6">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div>
                    <label class="font-bold text-xs">نوع گزارش</label>
                    <select class="select select-bordered select-sm w-full" wire:model.live="reportType">
                        <option value="tickets">تیکت‌ها</option>
                        <option value="todos">وظایف</option>
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
                        @foreach($childUnits as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="font-bold text-xs">واحد</label>
                    <select class="select select-bordered select-sm w-full" wire:model.live="unitId">
                        <option value="">همه</option>
                        @foreach($grandChildUnits as $u)
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
                <x-input label="از تاریخ" type="date" wire:model.live="dateFrom" class="w-full" />
                <x-input label="تا تاریخ" type="date" wire:model.live="dateTo" class="w-full" />
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
        </div>

        {{-- نمودارها --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <x-card shadow>
                <h3 class="font-bold mb-4">روند روزانه</h3>
                <div id="trendChart" style="height: 300px;"></div>
            </x-card>

            <x-card shadow>
                <h3 class="font-bold mb-4">توزیع بر اساس واحد</h3>
                <div id="unitChart" style="height: 300px;"></div>
            </x-card>
        </div>
    </div>

    @script
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const reportData = @json($this->reportData);

            // نمودار روند
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
