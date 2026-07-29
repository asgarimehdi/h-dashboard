<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-home" class="w-5 h-5 text-primary" />
            داشبورد مدیریت اطلاعات سلامت
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            داشبورد اصلی نمای کلی از وضعیت سیستم را ارائه می‌دهد. در این صفحه آمار کلی کاربران، پرسنل، واحدها، تیکت‌ها، وظایف و نقش‌ها نمایش داده می‌شود.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-chart-bar" class="w-5 h-5 text-info" />
            نمودارها و آمارها
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>روند تیکت‌ها (۳۰ روز اخیر):</strong> نمودار مساحی تعداد تیکت‌های ثبت‌شده در هر روز</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>وضعیت تیکت‌ها:</strong> نمودار دایره‌ای توزیع تیکت‌ها بر اساس وضعیت (جدید، در حال پردازش، تکمیل شده، ارجاع شده، رد شده)</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>وضعیت وظایف:</strong> نرخ تکمیل وظایف با نوار پیشرفت و آمار تفصیلی</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>ارتباط تیکت و وظایف:</strong> درصد وظایفی که حداقل یک تیکت مرتبط دارند</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-sun" class="w-5 h-5 text-warning" />
            خلاصه امروز
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            تعداد تیکت‌های جدید، وظایف جدید و کل فعالیت‌های امروز را یک نگاه مشاهده کنید.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-chart-bar" class="w-5 h-5 text-primary" />
            آمار تفصیلی تیکت‌ها
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 grid grid-cols-2 gap-4">
            <li class="flex gap-2"><span class="badge badge-error badge-sm">فوری</span> تیکت‌های اولویت بالا در انتظار بررسی</li>
            <li class="flex gap-2"><span class="badge badge-warning badge-sm">عادی</span> تیکت‌های اولویت معمولی</li>
            <li class="flex gap-2"><span class="badge badge-success badge-sm">کم‌اهمیت</span> تیکت‌های اولویت پایین</li>
            <li class="flex gap-2"><span class="badge badge-error badge-sm">سررسید گذشته</span> تیکت‌های از موعد گذشته</li>
            <li class="flex gap-2 col-span-2"><span class="badge badge-info badge-sm">میانگین حل (روز)</span> میانگین زمان حل تیکت‌های تکمیل شده</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-clock" class="w-5 h-5 text-primary" />
            آخرین فعالیت‌ها
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            ۱۰ فعالیت آخر سیستم با جزئیات کاربر، نوع فعالیت و زمان. کلیک روی «مشاهده همه» برای لاگ کامل فعالیت‌ها.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مفید
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• از منوی کناری برای دسترسی سریع به بخش‌های مختلف استفاده کنید</li>
            <li>• کلیدهای میانبر: <kbd class="kbd kbd-sm">Alt</kbd>+<kbd class="kbd kbd-sm">D</kbd> برای داشبورد، <kbd class="kbd kbd-sm">Alt</kbd>+<kbd class="kbd kbd-sm">H</kbd> برای سخت‌افزار</li>
            <li>• نوار پیشرفت در بالا نشان‌دهنده بارگذاری داده‌های زنده است</li>
        </ul>
    </div>
</div>