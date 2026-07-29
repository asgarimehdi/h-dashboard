<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-wifi" class="w-5 h-5 text-primary" />
            مانیتورینگ دستگاه‌های بی‌سیم
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            نمایش کیفیت سیگنال، فرکانس و زمان پاسخ برای لینک‌های رادیویی و مایکروویو. این بخش برای نظارت بر پایداری لینک‌های بی‌سیم طراحی شده است.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-gauge" class="w-5 h-5 text-info" />
            گیج‌های چندپارامتره (Multi-Gauge)
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 list-disc list-inside">
            <li><strong>Signal Strength (dBm):</strong> قدرت سیگنال دریافتی - مقادیر نزدیک‌تر به ۰ بهتر است</li>
            <li><strong>Frequency (MHz):</strong> فرکانس کاری لینک</li>
            <li><strong>Response Time (ms):</strong> تاخیر برداشت (Latency) - مقادیر کمتر بهتر است</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-chart-bar" class="w-5 h-5 text-warning" />
            محدوده‌های پیش‌فرض
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• سیگنال: -۸۵ تا -۴۵ dBm</li>
            <li>• فرکانس: بسته به باند (معمولاً ۵۰۰۰-۶۰۰۰ MHz)</li>
            <li>• زمان پاسخ: ۰ تا ۲۰۰ ms</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• داده‌ها از سیستم مانیتورینگ (زیبیکس) به‌صورت زنده خوانده می‌شوند</li>
            <li>• هر گیج با کلید یکتا (signalId+freqId+respId) به‌صورت lazy-load بارگذاری می‌شود</li>
            <li>• اگر گیجی نمایش داده نشد، آیتم‌های زیبیکس مربوطه را بررسی کنید</li>
            <li>• مقادیر dBm منفی هستند: -۴۵ قوی، -۸۵ ضعیف</li>
        </ul>
    </div>
</div>