<?php

use Livewire\Component;

return new class extends Component {
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
    ): void {
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

{{--
    نمایشی (Presentational) — این گیج دیگر خودش درخواست نمی‌فرستد.

    قبلاً هر گیج یک `fetch()` مستقل به `/api/zabbix/multi-latest` می‌زد و هر ۳۰ ثانیه
    تکرارش می‌کرد. با ۱۴ دستگاه بی‌سیم یعنی ۲۸ درخواست در دقیقه — و چون
    `x-init="init()"` کنار init خودکار Alpine اجرا می‌شد، بار اول **دو برابر**
    می‌شد؛ هر دو با هم از سقف `throttle:api-user` (۶۰ درخواست در دقیقه برای کل
    کاربر) عبور می‌کردند و صفحه بعد از چند ثانیه «Too Many Attempts» می‌داد.

    حالا صفحه `it/wireless` یک poller واحد دارد که همه آیتم‌ها را با **یک** درخواست
    می‌گیرد و نتیجه را در `window.zabbixGauges` می‌گذارد؛ هر گیج فقط مشترک آن است.
--}}
<div x-data="window.signalGauge(
    '{{ $signalItemId }}',
    '{{ $frequencyItemId }}',
    '{{ $responseTimeItemId }}',
    {{ $min }},
    {{ $max }},
    '{{ $unit }}',
    '{{ $frequencyUnit }}',
    '{{ $responseTimeUnit }}',
    '{{ $title }}'
)"
     class="flex flex-col items-center p-4 border rounded-lg shadow-sm bg-base-100 w-full">

    <!-- عنوان -->
    <div class="text-lg font-semibold mb-2" x-text="title"></div>

    <!-- گیج دایره‌ای -->
    <div class="relative w-32 h-32">
        <svg class="w-full h-full -rotate-90" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="8"
                    stroke-opacity="0.2" class="text-base-content/20"/>
            <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="8"
                    stroke-linecap="round" :stroke-dasharray="circumference"
                    :stroke-dashoffset="dashOffset"
                    :class="responseTimeColorClass + ' transition-all duration-500'"/>
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <div dir="ltr">
                <span class="text-2xl font-bold" x-text="displaySignal"></span>
                <span class="text-xs opacity-70" x-text="unit"></span>
            </div>
        </div>
    </div>

    <!-- مقادیر اضافی -->
    <div class="flex justify-around w-full mt-3 text-sm">
        <div class="text-center">
            <div class="text-xs opacity-70">فرکانس</div>
            <div class="font-medium">
                <span x-text="frequencyUnit"></span>
                <span dir="ltr" x-text="displayFrequency"></span>
            </div>
        </div>
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

@script
<script>
    // وضعیت مشترک را هر کسی که زودتر اجرا شود می‌سازد؛ گیج‌ها ممکن است پیش از
    // poller صفحه ساخته شوند، پس ساختن شیء باید در هر دو طرف idempotent باشد.
    window.zabbixGauges = window.zabbixGauges || {
        values: {}, error: null, loading: false, loaded: false, subscribers: [], timer: null,
    };

    // تعریف تابع در window برای دسترسی سراسری
    window.signalGauge = function(signalItemId, frequencyItemId, responseTimeItemId, min, max, unit, frequencyUnit, responseTimeUnit, title) {
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
            circumference: 2 * Math.PI * 45,
            bound: null,

            init() {
                const state = window.zabbixGauges;
                this.bound = this.apply.bind(this);
                state.subscribers.push(this.bound);
                this.apply();
            },

            /** مقادیر را از وضعیت مشترک می‌خواند؛ تنها جایی که به داده وصل می‌شود. */
            apply() {
                const state = window.zabbixGauges;
                const values = state.values || {};

                this.loading = state.loading;
                this.signal = values[this.signalItemId] ?? null;
                this.frequency = values[this.frequencyItemId] ?? null;
                this.responseTime = values[this.responseTimeItemId] ?? null;

                this.error = state.error;

                // تا وقتی اولین fetch تمام نشده «داده‌ای یافت نشد» زودهنگام است؛
                // قبلاً در این حالت فقط «—» نشان داده می‌شد.
                if (!this.error && state.loaded &&
                    this.signal === null && this.frequency === null && this.responseTime === null) {
                    this.error = 'داده‌ای یافت نشد';
                }
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

            get responseTimeColorClass() {
                if (this.responseTime === null) return 'text-base-content';
                const ms = this.responseTime * 1000;
                if (ms < 3) return 'text-success';
                if (ms >= 3 && ms <= 7) return 'text-warning';
                return 'text-error';
            },

            get dashOffset() {
                if (this.signal === null) return this.circumference;
                const percent = Math.min(100, Math.max(0, ((this.signal - this.min) / (this.max - this.min)) * 100));
                return this.circumference - (percent / 100) * this.circumference;
            },

            destroy() {
                const state = window.zabbixGauges;
                if (!state || !this.bound) return;
                const at = state.subscribers.indexOf(this.bound);
                if (at !== -1) state.subscribers.splice(at, 1);
            }
        };
    };
</script>
@endscript
