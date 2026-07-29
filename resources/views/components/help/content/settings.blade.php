<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-primary" />
            تنظیمات سیستم
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            مدیریت تنظیمات عمومی، ظاهر، اعلان‌ها و تنظیمات کاربری.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-palette" class="w-5 h-5 text-info" />
            تم و ظاهر
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• انتخاب تم: Light / Dark / System (پیش‌فرض سیستم)</li>
            <li>• انتخاب رنگ اصلی (Primary Color) از پیش‌تنظیم‌ها</li>
            <li>• تنظیمات RTL/LTR (پیش‌فرض RTL برای فارسی)</li>
            <li>• ذخیره تنظیمات در دیتابیس برای هر کاربر</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-bell" class="w-5 h-5 text-warning" />
            اعلان‌ها
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• فعال/غیرفعال کردن اعلان‌های مرورگر</li>
            <li>• نوع اعلان‌ها: تیکت‌های جدید، ارجاعات، یادآوری‌ها</li>
            <li>• زنگ اعلان در هدر برای مشاهده سریع</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-user" class="w-5 h-5 text-secondary" />
            پروفایل کاربری
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• تغییر نام نمایشی، ایمیل</li>
            <li>• تغییر رمز عبور</li>
            <li>• مدیریت جلسات فعال (خروج از دیگر دستگاه‌ها)</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-key" class="w-5 h-5 text-primary" />
            توکن‌های API (Sanctum)
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• ایجاد Personal Access Token برای دسترسی برنامه‌نویسی</li>
            <li>• نام توکن، انقضا، قابلیت‌های (abilities)</li>
            <li>• استفاده در Header: <code class="text-xs bg-base-200 px-1 rounded">Authorization: Bearer <token></code></li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• تنظیمات تم در localStorage و دیتابیس همزمان ذخیره می‌شود</li>
            <li>• توکن‌های API تنها یک بار نمایش داده می‌شوند (در لحظه ایجاد)</li>
            <li>• برای دسترسی به API از middleware <code class="text-xs bg-base-200 px-1 rounded">auth:sanctum</code> استفاده کنید</li>
        </ul>
    </div>
</div>