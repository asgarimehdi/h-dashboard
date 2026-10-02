<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-key" class="w-5 h-5 text-primary" />
            مدیریت مجوزها (Permissions)
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            فهرست دقیق پرمیشن‌هایی که در <code>PermissionSeeder</code> تعریف شده و در
            <code>routes/web.php</code> روی مسیرها و در سایدبار روی آیتم‌های منو اعمال می‌شوند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-cpu-chip" class="w-5 h-5 text-info" />
            سخت‌افزار و زیرساخت
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">manage_hardware</span> <span class="text-base-content/70">شناسنامه سخت‌افزار و زمان‌بندی تعمیرات (CRUD + AI)</span>
            <span class="badge badge-outline">manage_zabbix</span> <span class="text-base-content/70">مدیریت دستگاه‌های مانیتورینگ زبیکس</span>
            <span class="badge badge-outline">map</span> <span class="text-base-content/70">نقشه‌ها و داشبورد GIS</span>
            <span class="badge badge-outline">bw</span> <span class="text-base-content/70">آنالیز شبکه (صفحات شبکه‌ها و وایرلس‌ها)</span>
            <span class="badge badge-outline">op-cache</span> <span class="text-base-content/70">دسترسی به کش سرور (فقط خارج از production)</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-ticket" class="w-5 h-5 text-warning" />
            تیکتینگ و وظایف
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">create_ticket</span> <span class="text-base-content/70">ثبت تیکت جدید</span>
            <span class="badge badge-outline">view_assigned_tickets</span> <span class="text-base-content/70">مشاهده تیکت‌های ارجاع‌شده به خود</span>
            <span class="badge badge-outline">view_all_tickets</span> <span class="text-base-content/70">مانیتورینگ کل تیکت‌ها</span>
            <span class="badge badge-outline">manage_unit_tickets</span> <span class="text-base-content/70">مدیریت و ارجاع تیکت‌های واحد</span>
            <span class="badge badge-outline">calendar</span> <span class="text-base-content/70">تقویم کارها (وظایف)</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-user-group" class="w-5 h-5 text-success" />
            پرسنل، کارگزینی و سازمان
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">kargozini</span> <span class="text-base-content/70">کارگزینی و لیست پرسنل</span>
            <span class="badge badge-outline">manage_personnel</span> <span class="text-base-content/70">مدیریت پرسنل (API) و گزارش‌ها</span>
            <span class="badge badge-outline">organization</span> <span class="text-base-content/70">ساختار سازمانی و مدیریت واحدها</span>
            <span class="badge badge-outline">view_hr_dashboard</span> <span class="text-base-content/70">داشبورد منابع انسانی</span>
            <span class="badge badge-outline">manage_org_chart</span> <span class="text-base-content/70">چارت سازمانی</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-secondary" />
            مدیریت سیستم
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline">manage_users</span> <span class="text-base-content/70">مدیریت کاربران، گزارش فعالیت و ابزارها</span>
            <span class="badge badge-outline">manage_roles</span> <span class="text-base-content/70">مدیریت نقش‌ها و دسترسی‌ها</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-shield-check" class="w-5 h-5 text-info" />
            نحوه اعمال مجوزها
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• هر پرمیشن با <strong>همان نام</strong> در سه جا اعمال می‌شود: میدل‌ویر مسیر، گیت آیتم منو و کنترل Blade — این سه باید همیشه یکی باشند</li>
            <li>• کنترل در مسیرها: <code>middleware('role_or_permission:manage_hardware')</code> — با <code>|</code> می‌توان چند مجوز را «هرکدام» کرد</li>
            <li>• کنترل در Blade: <code>@@can('manage_hardware')</code> و <code>@@canany([...])</code> برای چند مجوز</li>
            <li>• <code>role_or_permission</code> هم نقش و هم پرمیشن را می‌پذیرد؛ روت‌های داخل UI از همین استفاده می‌کنند</li>
            <li>• پرمیشن‌های سطح بخش (مثل <code>map</code>) همیشه با «دسترسی سازمانی» (AccessService) ترکیب می‌شوند</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• نقش <strong>admin</strong> در <code>RoleSeeder</code> همه مجوزها را می‌گیرد؛ <strong>unit_manager</strong>، <strong>expert</strong> و <strong>user</strong> زیرمجموعه‌ای از آن هستند</li>
            <li>• با تغییر فهرست پرمیشن‌ها، <code>PermissionSeeder</code> و <code>RoleSeeder</code> باید روی دیتابیس هر محیط اجرا شوند؛ وگرنه پرمیشن تازه فقط برای ادمین‌های همگام‌شده معنا دارد</li>
            <li>• تغییر مجوزها بلافاصله اثر می‌کند (بدون نیاز به logout/login)</li>
            <li>• برای محروم کردن یک کاربر از صفحه‌ای، همان پرمیشن را در صفحه «مدیریت دسترسی‌ها» از او بگیرید</li>
        </ul>
    </div>
</div>
