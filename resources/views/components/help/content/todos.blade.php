<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-calendar-days" class="w-5 h-5 text-primary" />
            تقویم سازمانی
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            وظایف سازمانی و تیکت‌های مرتبط روی یک تقویم شمسی. همین صفحه هم‌زمان تقویم ماهانه،
            هفتگی و روزانه دارد و وظایف و رویدادهای تیکت را کنار هم نشان می‌دهد.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-calendar" class="w-5 h-5 text-info" />
            نمای تقویم
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• نوار بالای تقویم: «قبلی»/«بعدی» و «امروز» برای جابه‌جایی بین دوره‌ها</li>
            <li>• سه نمای «ماهانه»، «هفتگی» و «روزانه»؛ پیش‌فرض نمای ماهانه است</li>
            <li>• تقویم راست‌به‌چپ و شمسی است و هفته از شنبه شروع می‌شود</li>
            <li>• اگر رویدادهای یک روز جا نشوند، پیوند «بیشتر» باز می‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-plus-circle" class="w-5 h-5 text-success" />
            افزودن و ویرایش وظیفه
        </h4>
        <ol class="space-y-2 text-sm text-base-content/70 list-decimal list-inside">
            <li>روی یک خانهٔ خالی تقویم کلیک کنید؛ فرم «جزئیات تسک» با همان بازه از پیش پر می‌شود</li>
            <li>یا دکمهٔ «تسک جدید» در هدر را بزنید</li>
            <li>عنوان فعالیت را وارد کنید و تاریخ/ساعت شروع و پایان را با تقویم انتخاب کنید</li>
            <li>با «ذخیره» رویداد روی تقویم می‌نشیند</li>
        </ol>
        <p class="text-sm text-base-content/70 mt-2">
            کلیک روی یک رویدادِ وظیفه همان فرم را در حالت ویرایش باز می‌کند؛ در این حالت دکمهٔ
            «حذف کار» (با تأیید) هم در پایین فرم ظاهر می‌شود.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-arrows-right-left" class="w-5 h-5 text-warning" />
            جابه‌جایی و علامت‌گذاری انجام‌شده
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• رویداد وظیفه را با drag روی روز دیگر رها کنید؛ تاریخ‌ها خودکار به‌روز می‌شوند</li>
            <li>• آیکون دایره‌ای/تیک داخل هر رویداد، آن وظیفه را انجام‌شده یا در انتظار می‌کند</li>
            <li>• رویدادهای تیکت با drag جابه‌جا نمی‌شوند</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-swatch" class="w-5 h-5 text-secondary" />
            رنگ‌ها و رویدادهای تیکت
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li class="flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full bg-[#3b82f6]"></span> آبی: وظیفه در انتظار</li>
            <li class="flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full bg-[#10b981]"></span> سبز: وظیفه انجام شده</li>
            <li class="flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full bg-[#ef4444]"></span> قرمز: تیکت فوری</li>
            <li class="flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full bg-[#f59e0b]"></span> نارنجی: تیکت عادی</li>
            <li class="flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full bg-[#6b7280]"></span> خاکستری: تیکت کم‌اهمیت</li>
        </ul>
        <p class="text-sm text-base-content/70 mt-2">
            کلیک روی رویدادِ تیکت، این صفحه را ترک می‌کند و «صندوق تیکت‌ها» را باز می‌کند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• وظایف بر اساس واحد سازمانی فیلتر می‌شوند (دسترسی سازمانی)</li>
            <li>• تغییر بازه‌ها و جابه‌جایی رویدادها بلافاصله ذخیره می‌شود؛ نیازی به «ذخیره» نیست</li>
            <li>• تیکت‌ها فقط نمایش داده می‌شوند و از همین تقویم قابل ویرایش نیستند</li>
            <li>• گزارش وظایف در منوی «گزارش‌ها» موجود است</li>
            <li>• ورود به این بخش به مجوز <code class="badge badge-ghost badge-xs">calendar</code> نیاز دارد</li>
        </ul>
    </div>
</div>
