<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-key" class="w-5 h-5 text-primary" />
            مدیریت مجوزها (Permissions)
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            مجوزهای دقیق برای کنترل دسترسی به عملیات مختلف سیستم.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-list-bullet" class="w-5 h-5 text-info" />
            مجوزهای سخت‌افزار
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">manage_hardware</span> <span class="text-base-content/70">مدیریت کامل سخت‌افزار (CRUD + AI)</span>
            <span class="badge badge-outline">view_hardware</span> <span class="text-base-content/70">مشاهده سخت‌افزار</span>
            <span class="badge badge-outline">import_hardware</span> <span class="text-base-content/70">ایمپورت از اکسل</span>
            <span class="badge badge-outline">export_hardware</span> <span class="text-base-content/70">خروجی CSV/Excel</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-ticket" class="w-5 h-5 text-warning" />
            مجوزهای تیکتینگ
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">manage_tickets</span> <span class="text-base-content/70">مدیریت کامل تیکت‌ها</span>
            <span class="badge badge-outline">view_tickets</span> <span class="text-base-content/70">مشاهده تیکت‌ها</span>
            <span class="badge badge-outline">create_tickets</span> <span class="text-base-content/70">ایجاد تیکت جدید</span>
            <span class="badge badge-outline">assign_tickets</span> <span class="text-base-content/70">ارجاع/تخصیص تیکت</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-user-group" class="w-5 h-5 text-success" />
            مجوزهای پرسنل و کارگزینی
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">manage_personnel</span> <span class="text-base-content/70">مدیریت پرسنل</span>
            <span class="badge badge-outline">view_personnel</span> <span class="text-base-content/70">مشاهده پرسنل</span>
            <span class="badge badge-outline">manage_units</span> <span class="text-base-content/70">مدیریت واحدها</span>
            <span class="badge badge-outline">view_units</span> <span class="text-base-content/70">مشاهده واحدها</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-secondary" />
            مجوزهای تنظیمات و ادمین
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">manage_roles</span> <span class="text-base-content/70">مدیریت نقش‌ها</span>
            <span class="badge badge-outline">manage_permissions</span> <span class="text-base-content/70">مدیریت مجوزها</span>
            <span class="badge badge-outline">manage_settings</span> <span class="text-base-content/70">تنظیمات سیستم</span>
            <span class="badge badge-outline">view_reports</span> <span class="text-base-content/70">مشاهده گزارش‌ها</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-shield-check" class="w-5 h-5 text-info" />
            نحوه اعمال مجوزها
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• مجوزها به <strong>نقش‌ها (Roles)</strong> اختصاص داده می‌شوند</li>
            <li>• کاربران از طریق نقش‌ها مجوز می‌گیرند</li>
            <li>• کنترل در کنترلرها: <code>can('manage_hardware')</code></li>
            <li>• کنترل در Blade: <code>@can('manage_hardware')</code></li>
            <li>• میدل‌ویر مسیرها: <code>middleware('permission:manage_hardware')</code></li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• مجوز <code>manage_hardware</code> برای دسترسی به چت AI سخت‌افزار ضروری است</li>
            <li>• تغییر مجوزها بلافاصله اثر می‌کند (بدون نیاز به logout/login)</li>
            <li>• محدودیت واحد سازمانی (Organizational Scope) در کنار مجوزها بررسی می‌شود</li>
        </ul>
    </div>
</div>