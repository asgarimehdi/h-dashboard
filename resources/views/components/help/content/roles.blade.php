<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-shield-check" class="w-5 h-5 text-primary" />
            مدیریت نقش‌ها (Roles)
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            تعریف و مدیریت نقش‌های کاربری برای کنترل دسترسی بر اساس سطح سازمانی.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-plus-circle" class="w-5 h-5 text-success" />
            ایجاد نقش جدید
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 list-disc list-inside">
            <li>نام نقش (مثال: admin, operator, viewer, manager)</li>
            <li>نام نمایشی (فارسی)</li>
            <li>توصیف نقش</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-link" class="w-5 h-5 text-info" />
            تخصیص مجوزها به نقش
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• در فرم ایجاد/ویرایش نقش، مجوزها را تیک بزنید</li>
            <li>• مجوزها بر اساس ماژول دسته‌بندی شده‌اند</li>
            <li>• تغییرات بلافاصله اعمال می‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-user-plus" class="w-5 h-5 text-secondary" />
            تخصیص نقش به کاربر
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• از بخش «کاربران» یا پروفایل کاربر</li>
            <li>• یک کاربر می‌تواند چند نقش داشته باشد</li>
            <li>• مجوزهای نهایی = اجتماع مجوزهای تمام نقش‌های کاربر</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-shield-check" class="w-5 h-5 text-warning" />
            نقش‌های پیش‌فرض سیستم
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline badge-error">Admin</span> <span class="text-base-content/70">دسترسی کامل</span>
            <span class="badge badge-outline badge-warning">Operator</span> <span class="text-base-content/70">عملیات روزانه</span>
            <span class="badge badge-outline badge-info">Viewer</span> <span class="text-base-content/70">فقط مشاهده</span>
            <span class="badge badge-outline badge-success">Manager</span> <span class="text-base-content/70">مدیریت واحد</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• حذف نقشی که به کاربران داده شده، مجوزهای آن کاربران را می‌گیرد</li>
            <li>• مجوز <code>manage_hardware</code> برای دسترسی به سخت‌افزار و AI لازم است</li>
            <li>• کنترل دسترسی واحد سازمانی علاوه بر نقش‌ها اعمال می‌شود</li>
        </ul>
    </div>
</div>