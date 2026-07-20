<?php

use Livewire\Component;
use App\Models\Person;
use App\Services\AccessService;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    public string $dateFrom = '';
    public string $dateTo = '';
    public ?int $selectedUnitId = null;
    public ?string $selectedTahsilId = null;
    public ?string $selectedSematId = null;
    public ?string $selectedEstekhdamId = null;
    public $units = [];
    public $tahsils = [];
    public $semats = [];
    public $estekhdams = [];

    public function mount(): void
    {
        $this->dateFrom = Jalalian::fromCarbon(now()->subYears(1))->format('Y/m/d');
        $this->dateTo = Jalalian::fromCarbon(now())->format('Y/m/d');
        $this->units = \App\Models\Unit::all();
        $this->tahsils = \App\Models\Tahsil::all();
        $this->semats = \App\Models\Semat::all();
        $this->estekhdams = \App\Models\Estekhdam::all();
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

        $query = Person::query()
            ->when($accessibleIds, fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->when($this->selectedUnitId, fn($q) => $q->where('u_id', $this->selectedUnitId))
            ->when($this->selectedTahsilId, fn($q) => $q->where('t_id', $this->selectedTahsilId))
            ->when($this->selectedSematId, fn($q) => $q->where('s_id', $this->selectedSematId))
            ->when($this->selectedEstekhdamId, fn($q) => $q->where('e_id', $this->selectedEstekhdamId))
            ->with(['tahsil', 'semat', 'estekhdam', 'unit:id,name']);

        $total = $query->count();

        $byTahsil = (clone $query)->get()->groupBy(fn($p) => $p->tahsil?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())->toArray();
        $bySemat = (clone $query)->get()->groupBy(fn($p) => $p->semat?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())->toArray();
        $byEstekhdam = (clone $query)->get()->groupBy(fn($p) => $p->estekhdam?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())->toArray();
        $byUnit = (clone $query)->get()->groupBy(fn($p) => $p->unit?->name ?? 'نامشخص')
            ->map(fn($items) => $items->count())->toArray();

        $persons = (clone $query)
            ->orderBy('n_code')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'n_code' => $p->n_code,
                'name' => $p->name ?? '—',
                'unit' => $p->unit?->name ?? '—',
                'tahsil' => $p->tahsil?->name ?? '—',
                'semat' => $p->semat?->name ?? '—',
                'estekhdam' => $p->estekhdam?->name ?? '—',
            ])
            ->toArray();

        return [
            'total' => $total,
            'byTahsil' => $byTahsil,
            'bySemat' => $bySemat,
            'byEstekhdam' => $byEstekhdam,
            'byUnit' => $byUnit,
            'persons' => $persons,
        ];
    }

    public function getAccessibleUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return \App\Models\Unit::whereIn('id', $accessibleIds)->get();
    }
}; ?>

<div class="p-6" dir="rtl">
    <x-header title="گزارش پرسنل" separator progress-indicator>
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
                <label class="font-bold text-xs">تحصیلات</label>
                <select class="select select-bordered select-sm" wire:model.live="selectedTahsilId">
                    <option value="">همه</option>
                    @foreach($tahsils as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">سمت</label>
                <select class="select select-bordered select-sm" wire:model.live="selectedSematId">
                    <option value="">همه</option>
                    @foreach($semats as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">نوع استخدام</label>
                <select class="select select-bordered select-sm" wire:model.live="selectedEstekhdamId">
                    <option value="">همه</option>
                    @foreach($estekhdams as $e)
                        <option value="{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </x-card>

    {{-- آمار کلی --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">کل پرسنل</div>
            <div class="stat-value text-lg text-primary">{{ $this->chartPayload['total'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">تحصیلات</div>
            <div class="stat-value text-lg text-info">{{ count($this->chartPayload['byTahsil']) }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">سمت</div>
            <div class="stat-value text-lg text-success">{{ count($this->chartPayload['bySemat']) }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">نوع استخدام</div>
            <div class="stat-value text-lg text-warning">{{ count($this->chartPayload['byEstekhdam']) }}</div>
        </div>
    </div>

    {{-- نمودارها --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <x-card shadow>
            <h3 class="font-bold mb-4">بر اساس تحصیلات</h3>
            <div id="tahsilChart" wire:ignore style="height: 250px;"></div>
        </x-card>
        <x-card shadow>
            <h3 class="font-bold mb-4">بر اساس سمت</h3>
            <div id="sematChart" wire:ignore style="height: 250px;"></div>
        </x-card>
        <x-card shadow>
            <h3 class="font-bold mb-4">بر اساس استخدام</h3>
            <div id="estekhdamChart" wire:ignore style="height: 250px;"></div>
        </x-card>
    </div>

    {{-- جدول --}}
    <x-card shadow>
        <h3 class="font-bold mb-4">لیست پرسنل</h3>
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>کد ملی</th>
                        <th>نام</th>
                        <th>واحد</th>
                        <th>تحصیلات</th>
                        <th>سمت</th>
                        <th>استخدام</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->chartPayload['persons'] as $p)
                    <tr>
                        <td>{{ $p['id'] }}</td>
                        <td>{{ $p['n_code'] }}</td>
                        <td>{{ $p['name'] }}</td>
                        <td>{{ $p['unit'] }}</td>
                        <td>{{ $p['tahsil'] }}</td>
                        <td>{{ $p['semat'] }}</td>
                        <td>{{ $p['estekhdam'] }}</td>
                    </tr>
                    @endforeach
                    @if(empty($this->chartPayload['persons']))
                    <tr>
                        <td colspan="7" class="text-center text-base-content/40">موردی یافت نشد</td>
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

    function renderPie(containerId, data) {
        destroyChart(containerId);
        const labels = Object.keys(data || {});
        const values = Object.values(data || {});
        if (labels.length > 0) {
            const colors = ['#6366f1','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#f97316','#ec4899','#14b8a6','#a855f7'];
            Highcharts.chart(containerId, {
                chart: { type: 'pie' },
                title: { text: '' },
                series: [{ name: 'تعداد', data: labels.map((l, i) => ({ name: l, y: values[i], color: colors[i % colors.length] })) }],
                credits: { enabled: false },
                plotOptions: { pie: { dataLabels: { enabled: true, format: '{point.name}: {y}' } } }
            });
        }
    }

    async function render() {
        waitForHighcharts(async () => {
            const data = await $wire.chartPayload();
            renderPie('tahsilChart', data.byTahsil);
            renderPie('sematChart', data.bySemat);
            renderPie('estekhdamChart', data.byEstekhdam);
        });
    }

    render();
    $wire.$watch('selectedUnitId', () => render());
    $wire.$watch('selectedTahsilId', () => render());
    $wire.$watch('selectedSematId', () => render());
    $wire.$watch('selectedEstekhdamId', () => render());
</script>
@endscript