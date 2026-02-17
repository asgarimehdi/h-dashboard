<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $interface;
    public string $title;
    public int $initialDuration;

    public function mount($interface, $title = 'Traffic', $initialDuration = 3600)
    {
        $this->interface = $interface;
        $this->title = $title;
        $this->initialDuration = $initialDuration;
    }
};

?>

<div x-data="trafficChart(@js($interface), @js($title), @js($initialDuration))"
     x-init="init(); return () => destroy()"
     class="relative">

    <!-- کنترلر انتخاب بازه زمانی -->
    <div class="flex items-center gap-2 mb-2">
        <span class="text-sm font-medium">مدت زمان:</span>
        <select x-model="duration" class="select select-bordered select-sm">
            <option value="1800">30 دقیقه</option>
            <option value="3600">1 ساعت</option>
            <option value="7200">2 ساعت</option>
            <option value="14400">4 ساعت</option>
            <option value="21600">6 ساعت</option>
            <option value="43200">12 ساعت</option>
            <option value="86400">24 ساعت</option>
        </select>
    </div>

    <!-- محل رسم نمودار -->
    <div :id="'traffic-chart-' + interfaceId" class="relative"></div>

    <!-- Overlay لودینگ (فقط در هنگام بارگذاری اولیه و تغییر بازه نمایش داده می‌شود) -->
    <div x-show="loading"
         x-cloak
         class="absolute inset-0 flex items-center justify-center bg-base-100/50 z-10 rounded-lg">
        <span class="loading loading-spinner loading-lg text-primary"></span>
    </div>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</div>

<script>
function trafficChart(interfaceId, chartTitle, initialDuration) {
    return {
        interfaceId: interfaceId,
        chartTitle: chartTitle,
        duration: initialDuration,
        loading: false,
        chart: null,
        intervalId: null,

        init() {
            this.$nextTick(() => {
                this.initChart();
                // بارگذاری اولیه با نمایش لودینگ
                this.loadData(true);
                // به‌روزرسانی دوره‌ای بدون نمایش لودینگ
                this.intervalId = setInterval(() => this.loadData(false), 10000);
            });
            // واکنش به تغییر بازه زمانی با نمایش لودینگ
            this.$watch('duration', () => this.loadData(true));
        },

        initChart() {
            Highcharts.setOptions({
                time: { timezone: 'Asia/Tehran' }
            });
            this.chart = Highcharts.chart('traffic-chart-' + this.interfaceId, {
                chart: {
                    type: 'spline',
                    backgroundColor: 'transparent',
                    animation: Highcharts.svg,
                },
                title: {
                    text: this.chartTitle,
                    style: { color: "var(--color-primary)" }
                },
                xAxis: { type: 'datetime' },
                yAxis: { title: { text: 'Mbps' } },
                tooltip: { valueSuffix: ' Mbps' },
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
        },

        async loadData(showLoading = false) {
            // اگر showLoading true باشد، لودینگ را فعال می‌کنیم
            if (showLoading) this.loading = true;
            try {
                const res = await fetch(`/api/zabbix/traffic?interface=${this.interfaceId}&duration=${this.duration}`);
                const data = await res.json();
                this.chart.series[0].setData(data.out, false);
                this.chart.series[1].setData(data.in, false);
                this.chart.redraw();
            } catch(e) {
                console.error('Error fetching traffic:', e);
            } finally {
                // اگر showLoading true بود، لودینگ را غیرفعال می‌کنیم
                if (showLoading) this.loading = false;
            }
        },

        destroy() {
            if (this.intervalId) {
                clearInterval(this.intervalId);
            }
            if (this.chart) {
                this.chart.destroy();
            }
        }
    }
}
</script>

