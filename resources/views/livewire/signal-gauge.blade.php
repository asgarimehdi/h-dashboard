<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $signalItemId;
    public string $frequencyItemId;
    public string $responseTimeItemId;
    public string $title;
    public float $min;
    public float $max;
    public string $unit;
    public string $frequencyUnit;
    public string $responseTimeUnit;

    public function mount(
        string $signalItemId,
        string $frequencyItemId,
        string $responseTimeItemId,
        string $title = 'سیگنال',
        float $min = 0,
        float $max = 100,
        string $unit = '%',
        string $frequencyUnit = 'MHz',
        string $responseTimeUnit = 'ms'
    ) {
        $this->signalItemId = $signalItemId;
        $this->frequencyItemId = $frequencyItemId;
        $this->responseTimeItemId = $responseTimeItemId;
        $this->title = $title;
        $this->min = $min;
        $this->max = $max;
        $this->unit = $unit;
        $this->frequencyUnit = $frequencyUnit;
        $this->responseTimeUnit = $responseTimeUnit;
    }
};
?>

<div x-data="signalGauge(
    @js($signalItemId),
    @js($frequencyItemId),
    @js($responseTimeItemId),
    @js($min),
    @js($max),
    @js($unit),
    @js($frequencyUnit),
    @js($responseTimeUnit),
    @js($title)
)"
     x-init="init(); interval = setInterval(fetchValues, 10000)"
     class="flex flex-col items-center p-4 border rounded-lg shadow-sm bg-base-100 w-64">

    <!-- عنوان -->
    <div class="text-lg font-semibold mb-2" x-text="title"></div>

    <!-- گیج دایره‌ای -->
    <div class="relative w-32 h-32">
        <svg class="w-full h-full -rotate-90" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="8"
                    stroke-opacity="0.2" class="text-base-content/20"/>
            <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="8"
                    stroke-linecap="round" :stroke-dasharray="circumference"
                    :stroke-dashoffset="dashOffset" class="text-primary transition-all duration-500"/>
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <!-- نمایش عدد سیگنال با جهت چپ به راست برای حفظ علامت منفی -->
            <div dir="ltr">
                <span class="text-2xl font-bold" x-text="displaySignal"></span>
                <span class="text-xs opacity-70" x-text="unit"></span>
            </div>
        </div>
    </div>

    <!-- مقادیر اضافی (فرکانس و زمان پاسخ) -->
    <div class="flex justify-around w-full mt-3 text-sm">
        <!-- فرکانس: واحد قبل از عدد (برای RTL) -->
        <div class="text-center">
            <div class="text-xs opacity-70">فرکانس</div>
            <div class="font-medium">
                <span x-text="frequencyUnit"></span>
                <span dir="ltr" x-text="displayFrequency"></span>
            </div>
        </div>
        <!-- زمان پاسخ: واحد قبل از عدد (برای RTL) -->
        <div class="text-center">
            <div class="text-xs opacity-70">زمان پاسخ</div>
            <div class="font-medium">
                <span x-text="responseTimeUnit"></span>
                <span dir="ltr" x-text="displayResponseTime"></span>
            </div>
        </div>
    </div>

    <!-- خطا / لودینگ -->
    <div x-show="error" class="text-sm text-error mt-2" x-text="error"></div>
    <div x-show="loading" class="mt-2">
        <span class="loading loading-spinner loading-xs"></span>
    </div>
</div>

<script>
function signalGauge(signalItemId, frequencyItemId, responseTimeItemId, min, max, unit, frequencyUnit, responseTimeUnit, title) {
    return {
        signalItemId,
        frequencyItemId,
        responseTimeItemId,
        min, max, unit, frequencyUnit, responseTimeUnit, title,
        signal: null,
        frequency: null,
        responseTime: null,
        loading: false,
        error: null,
        interval: null,
        circumference: 2 * Math.PI * 45,

        init() {
            this.fetchValues();
        },

        get displaySignal() {
            return this.signal !== null ? this.signal.toFixed(1) : '—';
        },

        get displayFrequency() {
            return this.frequency !== null ? this.frequency.toFixed(1) : '—';
        },

        get displayResponseTime() {
            if (this.responseTime === null) return '—';
            const ms = this.responseTime * 1000;
            return ms >= 1 ? Math.round(ms) : ms.toFixed(1);
        },

        get dashOffset() {
            if (this.signal === null) return this.circumference;
            const percent = Math.min(100, Math.max(0, ((this.signal - this.min) / (this.max - this.min)) * 100));
            return this.circumference - (percent / 100) * this.circumference;
        },

        async fetchValues() {
            if (this.loading) return;
            this.loading = true;
            this.error = null;
            try {
                const itemIds = [this.signalItemId, this.frequencyItemId, this.responseTimeItemId];
                const params = new URLSearchParams();
                itemIds.forEach((id, index) => params.append(`item_ids[${index}]`, id));

                const token = localStorage.getItem('token');
                const headers = token ? { 'Authorization': `Bearer ${token}` } : {};

                const response = await fetch(`/api/zabbix/multi-latest?${params.toString()}`, { headers });

                if (!response.ok) {
                    let errorMsg = `خطای HTTP ${response.status}`;
                    try {
                        const text = await response.text();
                        try {
                            const errorData = JSON.parse(text);
                            if (errorData.message) errorMsg = errorData.message;
                        } catch {
                            errorMsg = text.substring(0, 100);
                        }
                    } catch (e) {}
                    throw new Error(errorMsg);
                }

                const data = await response.json();

                this.signal = data[this.signalItemId] ?? null;
                this.frequency = data[this.frequencyItemId] ?? null;
                this.responseTime = data[this.responseTimeItemId] ?? null;

                if (this.signal === null && this.frequency === null && this.responseTime === null) {
                    this.error = 'داده‌ای یافت نشد';
                }
            } catch (e) {
                console.error('Error fetching values:', e);
                this.error = e.message || 'خطا در دریافت';
            } finally {
                this.loading = false;
            }
        },

        destroy() {
            if (this.interval) clearInterval(this.interval);
        }
    };
}
</script>