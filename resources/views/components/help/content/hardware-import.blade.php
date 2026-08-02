<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-arrow-down-tray" class="w-5 h-5 text-primary" />
            ایمپورت سخت‌افزار از اکسل
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            وارد کردن انبوه دستگاه‌های سخت‌افزاری از فایل‌های Excel (.xlsx, .xls) یا CSV. فرآیند دو مرحله‌ای: پیش‌نمایش و تأیید.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-document-text" class="w-5 h-5 text-info" />
            فرمت فایل و ستون‌ها
        </h4>
        <table class="table table-xs w-full text-sm">
            <thead>
                <tr class="text-base-content/50">
                    <th>ستون</th>
                    <th>الزامی</th>
                    <th>توضیح</th>
                </tr>
            </thead>
            <tbody>
                <tr><td class="font-mono">n_code</td><td><span class="badge badge-error badge-xs">بله</span></td><td>کد ملی پرسنل مالک دستگاه</td></tr>
                <tr><td class="font-mono">pc_name</td><td><span class="badge badge-error badge-xs">بله</span></td><td>نام دستگاه (منحصر به فرد)</td></tr>
                <tr><td class="font-mono">type</td><td>خیر</td><td>نوع: pc, laptop, server, printer, ...</td></tr>
                <tr><td class="font-mono">os</td><td>خیر</td><td>سیستم‌عامل: Windows 10, Linux, ...</td></tr>
                <tr><td class="font-mono">ip_valid</td><td>خیر</td><td>IP عمومی</td></tr>
                <tr><td class="font-mono">ip_local</td><td>خیر</td><td>IP محلی (مثال: 192.168.1.100)</td></tr>
                <tr><td class="font-mono">mac</td><td>خیر</td><td>آدرس MAC (فرمت: AA:BB:CC:DD:EE:FF)</td></tr>
                <tr><td class="font-mono">cpu</td><td>خیر</td><td>مدل پردازنده</td></tr>
                <tr><td class="font-mono">ram</td><td>خیر</td><td>حافظه رم (مگابایت: 4096, 8192, 16384)</td></tr>
                <tr><td class="font-mono">hdd</td><td>خیر</td><td>نوع هارد: SSD, HDD, 500GB, 1TB</td></tr>
                <tr><td class="font-mono">shutdown</td><td>خیر</td><td>true/false یا بله/خیر - وضعیت روشن/خاموش</td></tr>
                <tr><td class="font-mono">mark</td><td>خیر</td><td>true/false - علامت‌گذاری</td></tr>
                <tr><td class="font-mono">comments</td><td>خیر</td><td>توضیحات</td></tr>
            </tbody>
        </table>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-arrow-right" class="w-5 h-5 text-primary" />
            مراحل ایمپورت
        </h4>
        <ol class="space-y-3 text-sm text-base-content/70 list-decimal list-inside">
            <li><strong>انتخاب فایل:</strong> فایل .xlsx, .xls یا .csv (حداکثر ۱۰ مگابایت) را انتخاب کنید</li>
            <li><strong>کلید مقایسه:</strong> انتخاب کنید دستگاه‌ها با چه فیلدهایی تطبیق داده شوند:
                <ul class="list-disc list-inside mt-1 space-y-1">
                    <li><code>pc_name</code>: فقط بر اساس نام دستگاه</li>
                    <li><code>mac</code>: فقط بر اساس آدرس MAC</li>
                    <li><code>both</code>: هر دو (پیش‌فرض، دقیق‌تر)</li>
                </ul>
            </li>
            <li><strong>پیش‌نمایش:</strong> دکمه «پیش‌نمایش» را بزنید. جدول نتایج به این صورت نمایش داده می‌شود:
                <ul class="list-disc list-inside mt-1 space-y-1">
                    <li><span class="badge badge-success badge-xs">جدید</span> - رکورد جدید ایجاد می‌شود</li>
                    <li><span class="badge badge-warning badge-xs">بروزرسانی</span> - رکورد موجود آپدیت می‌شود</li>
                    <li><span class="badge badge-info badge-xs">بدون تغییر</span> - داده‌ها یکسان است</li>
                    <li><span class="badge badge-error badge-xs">خطا</span> - مشکل در داده‌ها (پیام خطا نمایش داده می‌شود)</li>
                </ul>
            </li>
            <li><strong>تأیید و اجرا:</strong> اگر پیش‌نمایش درست است، دکمه «تأیید و اجرای ایمپورت» را بزنید</li>
        </ol>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-shield-check" class="w-5 h-5 text-success" />
            اعتبارسنجی و محدودیت‌ها
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• کد ملی پرسنل باید در سیستم وجود داشته باشد</li>
            <li>• پرسنل باید در واحدهای دسترسی‌پذیر شما باشد (محدوده سازمانی)</li>
            <li>• نام دستگاه (pc_name) در یک واحد نباید تکراری باشد</li>
            <li>• فرمت MAC Address باید استاندارد باشد (XX:XX:XX:XX:XX:XX)</li>
            <li>• مقادیر بولی: <code>true/false</code>، <code>1/0</code>، <code>بله/خیر</code>، <code>تایید/رد</code></li>
            <li>• برای خالی کردن فیلدها از <code>\\N</code> یا خالی بگذارید</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-warning/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-exclamation-triangle" class="w-4 h-4 text-warning" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• پیش‌نمایش تغییری در دیتابیس ایجاد نمی‌کند - فقط شبیه‌سازی است</li>
            <li>• تغییر «کلید مقایسه» پیش‌نمایش را بازسازی می‌کند</li>
            <li>• پس از تأیید، عملیات غیرقابل بازگشت است (ما عدا حذف دستی)</li>
            <li>• آمار ایمپورت (جدید/بروزرسانی/بدون تغییر/خطا) در نهایت نمایش داده می‌شود</li>
        </ul>
    </div>
</div>