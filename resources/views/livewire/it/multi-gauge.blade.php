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
<div x-data="signalGauge"
     data-signal-item-id="{{ $signalItemId }}"
     data-frequency-item-id="{{ $frequencyItemId }}"
     data-response-time-item-id="{{ $responseTimeItemId }}"
     data-min="{{ $min }}"
     data-max="{{ $max }}"
     data-unit="{{ $unit }}"
     data-frequency-unit="{{ $frequencyUnit }}"
     data-response-time-unit="{{ $responseTimeUnit }}"
     data-title="{{ $title }}"
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

