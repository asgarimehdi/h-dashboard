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
