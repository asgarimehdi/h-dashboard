<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-building-office-2" class="w-5 h-5 text-primary" />
            مدیریت واحدهای سازمانی
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            ساختار درختی واحدها، اطلاعات جغرافیایی، مرزها و کاربران منتسب.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-plus-circle" class="w-5 h-5 text-success" />
            افزودن واحد جدید
        </h4>
        <ol class="space-y-2 text-sm text-base-content/70 list-decimal list-inside">
            <li>دکمه «واحد جدید» در هدر</li>
            <li>نام واحد، واحد والد (برای سلسله‌مراتب)، نوع واحد، منطقه</li>
            <li>موقعیت جغرافیایی: طول و عرض جغرافیایی (برای نقشه)</li>
            <li>مرز جغرافیایی (پلیگون) - اختیاری، از صفحهٔ «نقشهٔ واحد» رسم می‌شود</li>
        </ol>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-circle-stack" class="w-5 h-5 text-success" />
            ساختار درختی و جستجو
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• نمای درختی باز/بسته‌شدنی با آیکون‌های گسترش و جمع‌کردن</li>
            <li>• جستجوی فوری بر اساس نام واحد (نتیجه جستجو درخت را فیلتر می‌کند)</li>
            <li>• کلیک روی یک واحد، پنل کنار صفحه را باز می‌کند: نوع، واحد والد، تعداد کاربران مستقیم و تعداد کاربران زیرمجموعه، و فهرست کاربران همان واحد</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-map-pin" class="w-5 h-5 text-info" />
            رسم، ذخیره و حذف مرز واحد
        </h4>
        <ol class="space-y-2 text-sm text-base-content/70 list-decimal list-inside">
            <li>از فهرست واحدها، صفحهٔ «نقشهٔ واحد» را باز کنید</li>
            <li>با ابزار ترسیم روی نقشه، پلیگون مرز را بکشید</li>
            <li>دکمهٔ «ذخیره» مرز را روی همین واحد ثبت می‌کند</li>
            <li>برای برداشتن مرز، «حذف مرز» را بزنید و تأیید کنید</li>
        </ol>
        <p class="text-sm text-base-content/70 mt-2">
            نشانگر بالای نقشه وضعیت را نشان می‌دهد: «مرز تعریف شده» یا «بدون مرز».
            حذف مرز فقط پلیگون را برمی‌دارد و واحد را از بین نمی‌برد؛ خود واحد و
            زیرمجموعه‌هایش سر جایشان می‌مانند و فقط بی‌مرز می‌شوند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-users" class="w-5 h-5 text-secondary" />
            کاربران منتسب به واحد
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• تخصیص کاربران سیستم به واحدها</li>
            <li>• یک کاربر می‌تواند به چند واحد دسترسی داشته باشد</li>
            <li>• کنترل دسترسی مبتنی بر واحد (Organizational Scope)</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• حذف واحد والد، زیرمجموعه‌ها را نیز حذف می‌کند (با احتیاط استفاده کنید)</li>
            <li>• واحدهای خارج از حوزهٔ دسترسی شما دکمهٔ ذخیره/حذف مرز را نشان نمی‌دهند</li>
            <li>• واحدهای غیرفعال در گزارش‌ها و جستجوها نمایش داده نمی‌شوند</li>
            <li>• مختصات جغرافیایی برای گزارش‌های مکانی و نقشه ضروری است</li>
            <li>• داده‌های مرجع: نوع واحد، منطقه - از منوی تنظیمات قابل مدیریت</li>
        </ul>
    </div>
</div>
