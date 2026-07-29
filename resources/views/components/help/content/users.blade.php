<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-users" class="w-5 h-5 text-primary" />
            مدیریت کاربران سیستم
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            مدیریت حساب‌های کاربری، رمز عبور، تخصیص واحدها و نقش‌ها.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-user-plus" class="w-5 h-5 text-success" />
            ایجاد کاربر جدید
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 list-disc list-inside">
            <li>نام کامل، ایمیل، رمز عبور</li>
            <li>کد ملی (برای اتصال به پرسنل) - اختیاری اما توصیه شده</li>
            <li>تخصیص واحدهای سازمانی (یک یا چند واحد)</li>
            <li>تخصیص نقش (Admin, Operator, Viewer, ...)</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-lock-open" class="w-5 h-5 text-warning" />
            مدیریت رمز عبور
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• تغییر رمز عبور کاربر توسط ادمین</li>
            <li>• کاربر می‌تواند رمز خود را در پروفایل تغییر دهد</li>
            <li>• سیاست رمز عبور: حداقل ۸ کاراکتر</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-building-office-2" class="w-5 h-5 text-info" />
            تخصیص واحدهای سازمانی
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• مودال انتخاب واحدها با درخت باز/بسته‌شدنی</li>
            <li>• می‌توان چندین واحد انتخاب کرد (برای مدیران منطقه‌ای)</li>
            <li>• دسترسی کاربر = اجتماع واحدهای اختصاص‌یافته</li>
            <li>• محدودیت واحد بر روی تمام ماژول‌ها اعمال می‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-pencil-square" class="w-5 h-5 text-secondary" />
            ویرایش و غیرفعال‌سازی
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• ویرایش اطلاعات پایه، واحدها، نقش‌ها</li>
            <li>• غیرفعال‌سازی کاربر (حذف منطقی - Soft Delete)</li>
            <li>• کاربر غیرفعال نمی‌تواند وارد شود اما داده‌ها حفظ می‌شوند</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• کاربر بدون واحد سازمانی نمی‌تواند داده‌ای ببیند (محدوده سازمانی خالی)</li>
            <li>• اتصال کد ملی به پرسنل برای گزارش‌های ترکیبی ضروری است</li>
            <li>• فقط Admin می‌تواند کاربران دیگر را مدیریت کند</li>
        </ul>
    </div>
</div>