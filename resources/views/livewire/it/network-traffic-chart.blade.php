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
     x-data="trafficChart"
     data-out-item-id="{{ $outItemId }}"
     data-in-item-id="{{ $inItemId }}"
     data-title="{{ $title }}"
     data-duration="{{ $initialDuration }}"
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

    <!-- #703: when Zabbix is unreachable the chart was silently blank. Surface
         the requested friendly message instead of an unexplained empty chart. -->
    <div x-show="error" x-cloak class="text-sm text-error mt-2" x-text="error"></div>

    <div x-show="loading" x-cloak
         class="absolute inset-0 flex items-center justify-center bg-base-100/50 z-10 rounded-lg">
        <span class="loading loading-spinner loading-lg text-primary"></span>
    </div>
</div>

@assets
<script src="{{ asset('js/chart/highcharts.js') }}"></script>
<style nonce="{{ Vite::cspNonce() }}">
    [x-cloak] { display: none !important; }
</style>

<script nonce="{{ Vite::cspNonce() }}">
    // پاکسازی همه chartهای قبلی
    Highcharts.charts.forEach(chart => {
        if (chart) chart.destroy();
    });

    // غیرفعال کردن accessibility
    Highcharts.setOptions({
        accessibility: { enabled: false }
    });
</script>
@endassets