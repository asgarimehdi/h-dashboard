<?php
use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

return new class extends Component
{
    public array $stats = [];

    public array $byUnit = [];

    public array $bySemat = [];

    public array $byTahsil = [];

    public array $byEstekhdam = [];

    public array $byRadif = [];

    public array $vacancies = [];

    public bool $showHelpModal = false;

    public function mount(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // Cache heavy aggregations (5 min) — Issue #223 perf note
        // Versioned by hr_stats_version so Person saved/deleted invalidates it immediately (#375)
        $version = Cache::get('hr_stats_version', 0);
        $cacheKey = 'hr:dashboard:v'.$version.':'.md5(implode(',', $accessibleIds));
        $data = Cache::remember($cacheKey, 300, function () use ($accessibleIds) {
            $persons = Person::whereIn('u_id', $accessibleIds);

            return [
                'total' => (clone $persons)->count(),
                'active' => (clone $persons)->where('status', 'active')->count(),
                'retired' => (clone $persons)->where('status', 'retired')->count(),
                'by_unit' => (clone $persons)->selectRaw('u_id, count(*) as total')
                    ->groupBy('u_id')->with('unit:id,name')->get()->map(fn ($p) => [
                        'name' => $p->unit?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_semat' => (clone $persons)->selectRaw('s_id, count(*) as total')
                    ->whereNotNull('s_id')->groupBy('s_id')->with('semat:id,name')->get()->map(fn ($p) => [
                        'name' => $p->semat?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_tahsil' => (clone $persons)->selectRaw('t_id, count(*) as total')
                    ->whereNotNull('t_id')->groupBy('t_id')->with('tahsil:id,name')->get()->map(fn ($p) => [
                        'name' => $p->tahsil?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_estekhdam' => (clone $persons)->selectRaw('e_id, count(*) as total')
                    ->whereNotNull('e_id')->groupBy('e_id')->with('estekhdam:id,name')->get()->map(fn ($p) => [
                        'name' => $p->estekhdam?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_radif' => (clone $persons)->selectRaw('r_id, count(*) as total')
                    ->whereNotNull('r_id')->groupBy('r_id')->with('radif:id,name')->get()->map(fn ($p) => [
                        'name' => $p->radif?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
            ];
        });

        $this->stats = $data;
        $this->byUnit = $data['by_unit'];
        $this->bySemat = $data['by_semat'];
        $this->byTahsil = $data['by_tahsil'];
        $this->byEstekhdam = $data['by_estekhdam'];
        $this->byRadif = $data['by_radif'];

        // Vacancies: units with zero personnel
        $this->vacancies = Unit::whereIn('id', $accessibleIds)
            ->withCount('person as personnel_count')
            ->get()
            ->filter(fn ($u) => $u->personnel_count === 0)
            ->take(10)
            ->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->toArray();
    }

    /**
     * Chart data for the JS layer (Highcharts) — called from the blade view
     * via $wire.chartPayload() so charts re-render without inlining PHP.
     */
    public function chartPayload(): array
    {
        return [
            'byUnit' => $this->byUnit,
            'bySemat' => $this->bySemat,
            'byTahsil' => $this->byTahsil,
            'byEstekhdam' => $this->byEstekhdam,
        ];
    }
};
?>

<div>
    <x-header title="داشبورد منابع انسانی" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="hr-dashboard" wireModel="showHelpModal" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    {{-- Overview stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-stat title="کل پرسنل" value="{{ $stats['total'] }}" icon="o-users" color="text-primary" />
        <x-stat title="فعال" value="{{ $stats['active'] }}" icon="o-user-circle" color="text-success" />
        <x-stat title="بازنشسته" value="{{ $stats['retired'] }}" icon="o-user-minus" color="text-warning" />
        <x-stat title="واحدهای بدون پرسنل" value="{{ count($vacancies) }}" icon="o-exclamation-triangle" color="text-error" />
    </div>

    {{-- Interactive Highcharts bar charts. Each chart is scaled to its own
         maximum, so a dominant category no longer squashes small rows into
         unreadable slivers (#494). Charts re-render on Livewire updates via
         the hook below. --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-card shadow title="پرسنل به تفکیک واحد">
            <div id="hrChartByUnit" class="w-full" style="height: {{ max(240, count($byUnit) * 28) }}px;"></div>
        </x-card>

        <x-card shadow title="پرسنل به تفکیک سمت">
            <div id="hrChartBySemat" class="w-full" style="height: {{ max(240, count($bySemat) * 28) }}px;"></div>
        </x-card>

        <x-card shadow title="تحصیلات">
            <div id="hrChartByTahsil" class="w-full" style="height: {{ max(240, count($byTahsil) * 32) }}px;"></div>
        </x-card>

        <x-card shadow title="نوع استخدام">
            <div id="hrChartByEstekhdam" class="w-full" style="height: {{ max(240, count($byEstekhdam) * 32) }}px;"></div>
        </x-card>
    </div>

    {{-- Vacancies --}}
    <x-card shadow title="واحدهای بدون پرسنل (Vacancies)" class="mt-4">
        @forelse ($vacancies as $v)
            <span class="badge badge-outline badge-error ml-1 mb-1">{{ $v['name'] }}</span>
        @empty
            <p class="text-sm text-base-content/50">همه واحدها پرسنل دارند 🎉</p>
        @endforelse
    </x-card>
</div>

@assets
<script src="{{ asset('js/chart/highcharts.js') }}"></script>
@endassets

@script
<script>
    // Palettes keyed by data-theme (see x-theme-selector: fantasy light / dark).
    // Bars, axis text and grid lines must follow the active DaisyUI theme.
    const HR_CHART_PALETTES = {
        fantasy: {
            colors: ['#6366f1', '#10b981', '#0ea5e9', '#f59e0b'],
            text: '#3f3f46',
            grid: '#e4e4e7'
        },
        dark: {
            colors: ['#a78bfa', '#34d399', '#38bdf8', '#fbbf24'],
            text: '#d4d4d8',
            grid: '#3f3f46'
        }
    };

    function hrPalette() {
        return HR_CHART_PALETTES[document.documentElement.dataset.theme] ?? HR_CHART_PALETTES.fantasy;
    }

    function destroyHrChart(id) {
        const container = document.getElementById(id);
        if (!container || typeof Highcharts === 'undefined') return;
        const existing = Highcharts.charts?.find(c => c && c.renderTo === container);
        if (existing) existing.destroy();
        container.innerHTML = '';
    }

    function renderHrBar(id, rows, color, palette) {
        destroyHrChart(id);
        const data = (rows || [])
            .slice()
            .sort((a, b) => b.total - a.total)
            .map(r => ({ name: r.name, y: r.total }));

        if (!data.length || typeof Highcharts === 'undefined') return;

        Highcharts.chart(id, {
            chart: { type: 'bar' },
            title: { text: '' },
            xAxis: {
                type: 'category',
                labels: { style: { fontSize: '12px', color: palette.text } }
            },
            yAxis: {
                title: { text: 'تعداد', style: { color: palette.text } },
                labels: { style: { color: palette.text } },
                gridLineColor: palette.grid,
                allowDecimals: false,
                minRange: 1
            },
            legend: { enabled: false },
            credits: { enabled: false },
            tooltip: { style: { color: palette.text } },
            series: [{
                name: 'تعداد پرسنل',
                data: data,
                color: color,
                dataLabels: {
                    enabled: true,
                    align: 'left',
                    format: '{y}',
                    style: { color: palette.text }
                }
            }]
        });
    }

    // Cached so a theme switch repaints instantly without a server round-trip.
    let hrChartData = null;

    function paintHrCharts() {
        if (!hrChartData) return;
        const palette = hrPalette();
        renderHrBar('hrChartByUnit', hrChartData.byUnit, palette.colors[0], palette);
        renderHrBar('hrChartBySemat', hrChartData.bySemat, palette.colors[1], palette);
        renderHrBar('hrChartByTahsil', hrChartData.byTahsil, palette.colors[2], palette);
        renderHrBar('hrChartByEstekhdam', hrChartData.byEstekhdam, palette.colors[3], palette);
    }

    async function renderHrCharts() {
        hrChartData = await $wire.chartPayload();
        paintHrCharts();
    }

    function waitForHighcharts(fn) {
        if (typeof Highcharts !== 'undefined') return fn();
        setTimeout(() => waitForHighcharts(fn), 100);
    }

    waitForHighcharts(renderHrCharts);
    $wire.$on('$refresh', () => renderHrCharts());

    // maryUI x-theme-toggle dispatches this bubbling CustomEvent on switch.
    window.addEventListener('theme-changed', () => paintHrCharts());
</script>
@endscript

<?php
