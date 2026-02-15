<?php


use Livewire\Volt\Component;

new class extends Component {

}

?>
<div id="traffic-chart"></div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        Highcharts.setOptions({
            time: {
                timezone: 'Asia/Tehran'
            }
        });
        const chart = Highcharts.chart('traffic-chart', {
            chart: {
                type: 'spline',
                backgroundColor: 'transparent',
                animation: Highcharts.svg, // smooth animation
            },
            title: {
                text: 'Interface Gi1/0/1 Traffic (Last 1 Hour)',
                style: { color: "var(--color-primary)" }
            },
            xAxis: {
                type: 'datetime',
                title: { text: 'Time' }
            },
            yAxis: {
                title: { text: 'Mbps' }
            },
            tooltip: {
                valueSuffix: ' Mbps'
            },
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


        async   function loadData() {
            try {
                const res = await fetch('/zabbix/traffic');
                const data = await res.json();

                chart.series[0].setData(data.out, false); // Outgoing
                chart.series[1].setData(data.in, false);  // Incoming
                chart.redraw();
            } catch(e) {
                console.error('Error fetching traffic:', e);
            }
        }

        loadData();
        setInterval(loadData, 5000); // هر 5 ثانیه آپدیت
    });
</script>

