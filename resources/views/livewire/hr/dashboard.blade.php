<?php
use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Livewire\Component;

return new class extends Component
{
    // Only the three scalars the overview tiles need. The five groupings live
    // in the by_* props below — keeping a copy of them in a $stats bag shipped
    // every grouping twice in each Livewire snapshot (#1174).
    public int $totalPersons = 0;

    public int $activePersons = 0;

    public int $retiredPersons = 0;

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
        // Versioned by `hr_stats` so Person saved/deleted invalidates it immediately (#375).
        // Issue #1290: the versioned key is built by the service, never by hand.
        $data = app(CacheInvalidationServiceInterface::class)->remember('hr_stats', \App\Support\ScopeCacheKey::hash($accessibleIds), function () use ($accessibleIds) {
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
        }, 5);

        $this->totalPersons = $data['total'];
        $this->activePersons = $data['active'];
        $this->retiredPersons = $data['retired'];
        $this->byUnit = $data['by_unit'];
        $this->bySemat = $data['by_semat'];
        $this->byTahsil = $data['by_tahsil'];
        $this->byEstekhdam = $data['by_estekhdam'];
        $this->byRadif = $data['by_radif'];

        // Vacancies: units with zero personnel. Filter and cap in SQL so the
        // database returns at most ten narrow rows instead of hydrating every
        // unit — geometry columns included — and discarding all but ten in PHP
        // (#1043). `whereDoesntHave('person')` is the same NOT EXISTS predicate
        // HrStatsController::vacancies() already uses. Ordering by name is new
        // and deliberate: the previous order was Postgres' physical order.
        $this->vacancies = Unit::whereIn('id', $accessibleIds)
            ->whereDoesntHave('person')
            ->select(['id', 'name'])
            ->orderBy('name')
            ->limit(10)
            ->get()
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
        <x-stat title="کل پرسنل" value="{{ $totalPersons }}" icon="o-users" color="text-primary" />
        <x-stat title="فعال" value="{{ $activePersons }}" icon="o-user-circle" color="text-success" />
        <x-stat title="بازنشسته" value="{{ $retiredPersons }}" icon="o-user-minus" color="text-warning" />
        <x-stat title="واحدهای بدون پرسنل" value="{{ count($vacancies) }}" icon="o-exclamation-triangle" color="text-error" />
    </div>

    {{-- Interactive Highcharts bar charts. Each chart is scaled to its own
         maximum, so a dominant category no longer squashes small rows into
         unreadable slivers (#494). Charts re-render on Livewire updates via
         the hook below. --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-card shadow title="پرسنل به تفکیک واحد">
            <div id="hrChartByUnit" class="w-full hr-chart"></div>
        </x-card>

        <x-card shadow title="پرسنل به تفکیک سمت">
            <div id="hrChartBySemat" class="w-full hr-chart"></div>
        </x-card>

        <x-card shadow title="تحصیلات">
            <div id="hrChartByTahsil" class="w-full hr-chart"></div>
        </x-card>

        <x-card shadow title="نوع استخدام">
            <div id="hrChartByEstekhdam" class="w-full hr-chart"></div>
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
{{-- Issue #1352: chart heights scale with the row counts, so they live in this
     nonce'd block (rendered server-side) instead of `style=` attributes. --}}
<style nonce="{{ Vite::cspNonce() }}">
    #hrChartByUnit { height: {{ max(240, count($byUnit) * 28) }}px; }
    #hrChartBySemat { height: {{ max(240, count($bySemat) * 28) }}px; }
    #hrChartByTahsil { height: {{ max(240, count($byTahsil) * 32) }}px; }
    #hrChartByEstekhdam { height: {{ max(240, count($byEstekhdam) * 32) }}px; }
</style>

</div>

@assets
<script src="{{ asset('js/chart/highcharts.js') }}"></script>
@endassets

@script
<script>
    // Bars need a per-family palette (daisyUI's own `color-scheme` is the same
    // signal App\Support\ThemeFamilies uses: light/cupcake/fantasy = light,
    // dark/synthwave/coffee/luxury = dark). Text and grid read the active
    // theme's tokens, so a newly enabled theme needs no table entry here.
    const HR_CHART_COLORS = {
        light: ['#4f46e5', '#047857', '#0369a1', '#b45309'],
        dark: ['#a78bfa', '#34d399', '#38bdf8', '#fbbf24']
    };

    function hrPalette() {
        const root = document.documentElement;
        const style = getComputedStyle(root);
        const scheme = (style.colorScheme || '').includes('dark') ? 'dark' : 'light';
        const token = (name, fallback) => style.getPropertyValue(name).trim() || fallback;

        return {
            colors: HR_CHART_COLORS[scheme],
            text: token('--color-base-content', scheme === 'dark' ? '#d4d4d8' : '#3f3f46'),
            grid: token('--color-base-300', scheme === 'dark' ? '#3f3f46' : '#e4e4e7')
        };
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
