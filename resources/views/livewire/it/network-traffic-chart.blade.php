<?php

use Livewire\Component;

return new class extends Component {
    public string $outItemId;
    public string $inItemId;
    public string $title;
    public int $initialDuration;

    public function mount($outItemId, $inItemId, $title = 'Traffic', $initialDuration = 3600): void
    {
        $this->outItemId = $outItemId;
        $this->inItemId = $inItemId;
        $this->title = $title;
        $this->initialDuration = $initialDuration;
    }
};
?>

<div wire:key="traffic-{{ $outItemId }}-{{ $inItemId }}"
     x-data="initTrafficChart('{{ $outItemId }}', '{{ $inItemId }}', '{{ $title }}', {{ $initialDuration }})"
     x-init="start($el)"
     class="relative">

    <div class="flex items-center gap-2 mb-2">
        <span class="text-sm font-medium">مدت زمان:</span>
        <select x-model="duration" @change="reload()" class="select select-bordered select-sm">
            <option value="1800">30 دقیقه</option>
            <option value="3600">1 ساعت</option>
            <option value="7200">2 ساعت</option>
            <option value="14400">4 ساعت</option>
            <option value="21600">6 ساعت</option>
            <option value="43200">12 ساعت</option>
            <option value="86400">24 ساعت</option>
        </select>
    </div>

    <div x-ref="container" class="w-full h-[300px]"></div>

    <div x-show="loading" x-cloak
         class="absolute inset-0 flex items-center justify-center bg-base-100/50 z-10 rounded-lg">
        <span class="loading loading-spinner loading-lg text-primary"></span>
    </div>
</div>

@assets
<script src="https://code.highcharts.com/highcharts.js"></script>
<style>
    [x-cloak] { display: none !important; }
</style>
@endassets

@script
<script>
    // پاکسازی همه chartهای قبلی
    Highcharts.charts.forEach(chart => {
        if (chart) chart.destroy();
    });
    
    // غیرفعال کردن accessibility
    Highcharts.setOptions({
        accessibility: { enabled: false }
    });

    window.initTrafficChart = function(outItemId, inItemId, chartTitle, initialDuration) {
        return {
            outItemId, inItemId, chartTitle,
            duration: initialDuration,
            loading: false,
            chart: null,
            timer: null,

            start(el) {
                this.$nextTick(() => {
                    const container = el.querySelector('[x-ref="container"]');
                    if (!container) return;
                    
                    // پاکسازی container
                    container.innerHTML = '';
                    
                    this.chart = Highcharts.chart(container, {
                        chart: {
                            type: 'spline',
                            backgroundColor: 'transparent',
                            animation: false
                        },
                        title: {
                            text: this.chartTitle,
                            style: { color: "var(--color-primary)" }
                        },
                        xAxis: { type: 'datetime' },
                        yAxis: { title: { text: 'Mbps' } },
                        tooltip: { valueSuffix: ' Mbps' },
                        credits: { enabled: false },
                        accessibility: { enabled: false },
                        series: [{
                            name: 'Outgoing',
                            data: [],
                            color: "var(--color-warning)"
                        }, {
                            name: 'Incoming',
                            data: [],
                            color: "var(--color-primary)"
                        }]
                    });
                    
                    this.load();
                    this.timer = setInterval(() => this.load(), 10000);
                });
            },

            reload() {
                this.load(true);
            },

            async load(show = false) {
                if (!this.chart) return;
                if (show) this.loading = true;
                
                try {
                    const url = `/api/zabbix/traffic?out_item_id=${this.outItemId}&in_item_id=${this.inItemId}&duration=${this.duration}`;
                    const res = await fetch(url);
                    const data = await res.json();
                    
                    if (this.chart && this.chart.series) {
                        this.chart.series[0].setData(data.out || [], false);
                        this.chart.series[1].setData(data.in || [], false);
                        this.chart.redraw(false);
                    }
                } catch(e) {
                    console.error(e);
                } finally {
                    if (show) this.loading = false;
                }
            },

            destroy() {
                clearInterval(this.timer);
                if (this.chart) {
                    this.chart.destroy();
                    this.chart = null;
                }
            }
        };
    };
</script>
@endscript