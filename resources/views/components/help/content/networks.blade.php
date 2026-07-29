<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-server" class="w-5 h-5 text-primary" />
            مانیتورینگ ترافیک شبکه
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            نمایش ترافیک ورودی/خروجی برای لینک‌های فیبر و سوئیچ‌های هسته شبکه. نمودارهای بلادرنگ ترافیک را برای هر لینک جداگانه ترسیم می‌کند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-chart-bar" class="w-5 h-5 text-info" />
            نمودارهای ترافیک (Highcharts)
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 list-disc list-inside">
            <li>نمودارهای مساحت‌ای (Area Chart) برای نمایش روند ترافیک</li>
            <li>بازه زمانی پیش‌فرض: ۲ ساعت اخیر (۷۲۰۰ ثانیه)</li>
            <li>ترکیب In/Out traffic در یک نمودار</li>
            <li>واحد: بایت بر ثانیه (B/s) یا بیت بر ثانیه (bps)</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-warning" />
            جزئیات لینک‌ها
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• هر لینک شامل دو آیتم زیبیکس: In Traffic و Out Traffic</li>
            <li>• شناسه‌ها (out-item-id، in-item-id) از زیبیکس گرفته شده‌اند</li>
            <li>• برای اضافه کردن لینک جدید، آرایه networkItems در کنترلر ویرایش شود</li>
            <li>• نمودارها با island lazy-loading بارگذاری می‌شوند</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• داده‌ها مستقیماً از زیبیکس (Zabbix) API خوانده می‌شوند</li>
            <li>• اگر نمودار خالی بود، بررسی کنید آیتم‌های زیبیکس فعال باشند</li>
            <li>• برای فیبرها معمولاً In/Out ترافیک متمایز است</li>
            <li>• واحد نمایش بر اساس تنظیمات Highcharts می‌تواند B/s یا bps باشد</li>
        </ul>
    </div>
</div>