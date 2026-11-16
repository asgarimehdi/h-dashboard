<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-map" class="w-5 h-5 text-primary" />
            نقشه‌های تعاملی و تحلیل مکانی
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            نمایش بصری داده‌های مکانی روی نقشه: واحدها، سخت‌افزار، تیکت‌ها، لایهٔ تجمیعی بیماری‌ها،
            تقسیم‌بندی شهرستان، نقاط لوکیشن و محاسبهٔ فاصلهٔ جاده‌ای.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
            لایه‌ها و نشانگرها
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>واحدها:</strong> نشانگر واحدهای سازمانی روی نقشه</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>سخت‌افزار:</strong> موقعیت دستگاه‌هایی که مختصات دارند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>تیکت‌ها:</strong> تیکت‌های مرتبط با واحدها</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>بیماری‌ها:</strong> لایهٔ تجمیعی و بدون داده هویتی</li>
        </ul>
        <p class="text-sm text-base-content/70 mt-3">
            هر لایه با دکمهٔ خودش در نوار بالای نقشه روشن/خاموش می‌شود و سه شمارندهٔ
            «واحدها»، «سخت‌افزار» و «تیکت باز» وضعیت فعلی را نشان می‌دهند. کلیک روی یک واحد،
            پنجرهٔ «جزئیات واحد» (نام، نوع، موقعیت و تعداد زیرمجموعه) را باز می‌کند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-funnel" class="w-5 h-5 text-secondary" />
            فیلترها
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• <strong>نوع سخت‌افزار:</strong> لپ‌تاپ، دسکتاپ، سرور، پرینتر — فقط وقتی لایهٔ سخت‌افزار روشن است</li>
            <li>• <strong>اولویت تیکت</strong> و <strong>وضعیت تیکت:</strong> ایجاد شده، ارجاع شده، پذیرفته شده، تکمیل شده</li>
        </ul>
        <p class="text-sm text-base-content/70 mt-2">
            فیلترها پس از تغییر، لایهٔ مربوط را دوباره از سرور می‌گیرند؛ بقیهٔ لایه‌ها دست‌نخورده می‌مانند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-bug-ant" class="w-5 h-5 text-error" />
            لایهٔ تجمیعی بیماری‌ها
        </h4>
        <ol class="space-y-2 text-sm text-base-content/70 list-decimal list-inside">
            <li>دکمهٔ «بیماری‌ها» را روشن کنید؛ ردیف کنترل‌ها زیر نوار لایه‌ها باز می‌شود</li>
            <li>بیماری موردنظر را از فهرست انتخاب کنید</li>
            <li>در صورت نیاز بازهٔ «از تاریخ» و «تا تاریخ» را با تقویم شمسی محدود کنید</li>
            <li>در نمای شهرستانی، راهنمای رنگ‌ها (legend) با نرخ «در هر ۱۰٬۰۰۰ نفر» نمایش داده می‌شود</li>
        </ol>
        <p class="text-sm text-base-content/70 mt-2">
            با بزرگ‌نمایی روی یک واحد، نمایش از سطح شهرستان به سطح همان واحد تغییر می‌کند و راهنمای
            رنگ‌ها پنهان می‌شود. اگر دریافت داده ناموفق باشد، پیام خطا زیر همان ردیف و — در صورت
            منقضی‌شدن نشست — یک نوار سراسری بالای نقشه نشان داده می‌شود؛ خود نقشه فعال می‌ماند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-map-pin" class="w-5 h-5 text-warning" />
            دیگر صفحات این بخش
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• <strong>تقسیم‌بندی شهرستان:</strong> روشن/خاموش کردن مرز هر شهرستان با کلید کنار نام آن</li>
            <li>• <strong>نقاط لوکیشن:</strong> نشانگرهای واحدهای دارای مختصات، با فیلتر منطقه و نوع واحد</li>
            <li>• <strong>محاسبهٔ فاصلهٔ جاده‌ای:</strong> مسیر بین دو نقطه، فاصلهٔ کیلومتری و زمان تقریبی سفر</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• واحدها باید مختصات جغرافیایی داشته باشند تا روی نقشه دیده شوند</li>
            <li>• لایهٔ بیماری‌ها فقط شمارش و نرخ برمی‌گرداند؛ هیچ نام یا شناسهٔ بیماری ثبت نمی‌شود</li>
            <li>• مرز واحدها از صفحهٔ «نقشهٔ واحد» در بخش واحدهای سازمانی رسم و ذخیره می‌شود</li>
            <li>• ورود به این بخش به مجوز <code class="badge badge-ghost badge-xs">map</code> نیاز دارد</li>
        </ul>
    </div>
</div>
