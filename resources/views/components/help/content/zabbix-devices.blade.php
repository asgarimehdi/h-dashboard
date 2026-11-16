<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-cpu-chip" class="w-5 h-5 text-primary" />
            مدیریت دستگاه‌های زیبیکس
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            این صفحه فهرست دستگاه‌های مانیتورینگ را نگه می‌دارد: هر دستگاه می‌گوید چارت ترافیک یا گیج بی‌سیم کدام آیتم‌های زیبیکس را بخواند. صفحه‌های «شبکه» و «بی‌سیم» چارت‌هایشان را از همین فهرست می‌سازند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-plus-circle" class="w-5 h-5 text-success" />
            افزودن دستگاه
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>دکمهٔ «دستگاه جدید»:</strong> فرم را باز می‌کند؛ با «ذخیره» ثبت می‌شود و با «لغو» بی‌تغییر بسته می‌شود</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>عنوان:</strong> همان چیزی است که بالای چارت یا گیج دیده می‌شود</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>نوع:</strong> «شبکه» برای چارت ترافیک و «بی‌سیم» برای گیج لینک رادیویی؛ با عوض شدن نوع، فیلدهای همان نوع جای بقیه را می‌گیرند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>ترتیب و فعال:</strong> «ترتیب» جای دستگاه را در صفحه‌های شبکه/بی‌سیم تعیین می‌کند و کلید «فعال» آن را در آن صفحه‌ها نشان می‌دهد یا نه</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>نوع «شبکه»:</strong> «شناسه آیتم خروجی» و «شناسه آیتم ورودی» برای چارت ترافیک، به‌همراه «بازه پیش‌فرض (ثانیه)» که بازهٔ اولیهٔ نمودار است</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>نوع «بی‌سیم»:</strong> «شناسه سیگنال»، «شناسه فرکانس» و «شناسه زمان پاسخ»، و «حداقل»/«حداکثر» که بازهٔ گیج را می‌سازند (پیش‌فرض ‎-85‎ تا ‎-45‎)</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> کادر «جستجو...» فهرست را بر اساس عنوان فیلتر می‌کند و با کلیک روی سرستون‌های قابل مرتب‌سازی، جدول مرتب می‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-list-bullet" class="w-5 h-5 text-primary" />
            جدول دستگاه‌ها
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> ستون «شناسه آیتم‌ها» همان آیتم‌هایی را نشان می‌دهد که ثبت کرده‌اید؛ خالی بودنش یعنی دستگاهی بدون آیتم ذخیره شده است</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> ستون «وضعیت» با بج سبز «فعال» یا بج خاکستری «غیرفعال» وضعیت نمایش در صفحه‌های دیگر را می‌گوید</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> تعداد ردیف هر صفحه با انتخابگر پایین جدول عوض می‌شود (۱۰، ۲۰ یا ۵۰)</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-signal" class="w-5 h-5 text-info" />
            تست اتصال و وضعیت
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>«تست اتصال»:</strong> یک پرسش زنده از Zabbix API می‌فرستد و کنار همان ردیف نتیجه را نشان می‌دهد؛ بج سبز «اتصال برقرار» یا بج قرمز «خطا» که متن خطا در tooltip آن است</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>ویرایش:</strong> همان فرم را با مقادیر فعلی دستگاه باز می‌کند؛ با «ذخیره» جایگزین می‌شود</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>فعال/غیرفعال:</strong> آیکن چشم بدون حذف ردیف، وضعیت را برمی‌گرداند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>حذف:</strong> پس از تأیید پیام «آیا مطمئن هستید؟» ردیف را برای همیشه پاک می‌کند</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• ورود به این صفحه مجوز <strong>manage_zabbix</strong> می‌خواهد؛ دیدن صفحه‌های شبکه و بی‌سیم مجوز <strong>map</strong> یا <strong>bw</strong> دارد</li>
            <li>• شناسه‌های آیتم از هاست زیبیکس می‌آیند (آیتم‌های ترافیک، سیگنال، فرکانس و زمان پاسخ)؛ شناسهٔ اشتباه چارت را خالی می‌گذارد</li>
            <li>• در <strong>/it/networks</strong> و <strong>/it/wireless</strong> فقط دستگاه‌های <strong>فعال</strong> رندر می‌شوند؛ پس غیرفعال کردن یک دستگاه چارت آن را از آن صفحه‌ها برمی‌دارد</li>
            <li>• حذف دائمی است و چارت‌های آن دستگاه را هم می‌برد؛ اگر فقط می‌خواهید موقتاً پنهان شود، غیرفعالش کنید</li>
        </ul>
    </div>
</div>
