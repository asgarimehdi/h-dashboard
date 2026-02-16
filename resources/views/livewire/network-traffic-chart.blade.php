<?php

use Livewire\Volt\Component;

new class extends Component {

    public string $interface;
    public string $title;

    public function mount($interface, $title = 'Traffic')
    {
        $this->interface = $interface;
        $this->title = $title;
    }
}

?>

<div id="traffic-chart-{{ $interface }}"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {

    Highcharts.setOptions({
        time: { timezone: 'Asia/Tehran' }
    });

    const interfaceId = @js($interface);
    const chartTitle = @js($title);
    const chartElement = 'traffic-chart-' + interfaceId;

    const chart = Highcharts.chart(chartElement, {
        chart: {
            type: 'spline',
            backgroundColor: 'transparent',
            animation: Highcharts.svg,
        },
        title: {
            text: chartTitle,
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

    async function loadData() {
        try {
            const res = await fetch(`/api/zabbix/traffic?interface=${interfaceId}`);
            const data = await res.json();

            chart.series[0].setData(data.out, false);
            chart.series[1].setData(data.in, false);
            chart.redraw();
        } catch(e) {
            console.error('Error fetching traffic:', e);
        }
    }

    loadData();
    setInterval(loadData, 10000);
});
</script>


