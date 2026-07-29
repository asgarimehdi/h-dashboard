<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-clock" class="w-5 h-5 text-primary" />
            لاگ فعالیت‌ها (Activity Log)
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            ثبت و مشاهده تمام رویدادهای مهم سیستم برای ردیابی، حسابرسی و عیب‌یابی.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-list-bullet" class="w-5 h-5 text-info" />
            انواع فعالیت‌های ثبت‌شده
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="badge badge-outline badge-success">create</span> <span class="text-base-content/70">ایجاد رکورد جدید</span>
            <span class="badge badge-outline badge-primary">update</span> <span class="text-base-content/70">بروزرسانی رکورد</span>
            <span class="badge badge-outline badge-error">delete</span> <span class="text-base-content/70">حذف رکورد</span>
            <span class="badge badge-outline badge-info">login</span> <span class="text-base-content/70">ورود کاربر</span>
            <span class="badge badge-outline badge-warning">logout</span> <span class="text-base-content/70">خروج کاربر</span>
            <span class="badge badge-outline badge-secondary">assign</span> <span class="text-base-content/70">ارجاع/تخصیص</span>
        </div>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-eye" class="w-5 h-5 text-secondary" />
            اطلاعات هر فعالیت
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• <strong>کاربر:</strong> نام و شناسه کاربری</li>
            <li>• <strong>نوع:</strong> create, update, delete, login, logout, ...</li>
            <li>• <strong>توضیحات:</strong> متن توصیف فعالیت</li>
            <li>• <strong>مدل و شناسه:</strong> مدل Eloquent و ID رکورد تحت تأثیر</li>
            <li>• <strong>زمان:</strong> تاریخ و ساعت دقیق (شمسی)</li>
            <li>• <strong>IP Address:</strong> آدرس IP درخواست‌کننده</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-magnifying-glass" class="w-5 h-5 text-info" />
            فیلتر و جستجو
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• جستجوی متنی در توضیحات</li>
            <li>• فیلتر بر اساس نوع فعالیت</li>
            <li>• فیلتر بر اساس کاربر</li>
            <li>• بازه تاریخ (از/تا) با انتخاب‌گر تقویم شمسی</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-arrow-down-tray" class="w-5 h-5 text-primary" />
            خروجی و گزارش
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• صفحه‌بندی برای لیست‌های طولانی</li>
            <li>• امکان کپی/دانلود برای گزارش‌های حسابرسی</li>
            <li>• نمایش ۱۰ فعالیت آخر در داشبورد اصلی</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• لاگ فعالیت‌ها قابل حذف نیست (برای حسابرسی)</li>
            <li>• از پکیج <code>spatie/laravel-activitylog</code> استفاده شده</li>
            <li>• تمام ماژول‌ها (سخت‌افزار، تیکت، پرسنل، واحد، ...) لاگ می‌شوند</li>
            <li>• در داشبورد: ۱۰ فعالیت اخیر با آیکون رنگی نمایش داده می‌شود</li>
        </ul>
    </div>
</div>