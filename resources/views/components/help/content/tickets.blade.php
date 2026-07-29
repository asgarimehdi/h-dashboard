<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-ticket" class="w-5 h-5 text-primary" />
            سیستم تیکتینگ و پیگیری درخواست‌ها
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            ثبت، پیگیری، ارجاع و بستن تیکت‌های پشتیبانی و درخواست‌های داخلی.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-plus-circle" class="w-5 h-5 text-success" />
            ایجاد تیکت جدید
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 list-disc list-inside">
            <li>موضوع (Subject) و توضیحات (Content) - الزامی</li>
            <li>اولویت: <span class="badge badge-error">فوری</span> <span class="badge badge-warning">بالا</span> <span class="badge badge-info">متوسط</span> <span class="badge badge-primary">عادی</span> <span class="badge badge-success">کم</span></li>
            <li>واحد مقصد - الزامی</li>
            <li>ضمیمه فایل (اختیاری)</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-inbox" class="w-5 h-5 text-info" />
            صندوق ورودی (Inbox) - تیکت‌های ارجاع شده به شما
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• تیکت‌هایی که به واحد شما ارجاع شده‌اند</li>
            <li>• وضعیت: <strong>جدید</strong>، <strong>پذیرفته شده</strong>، <strong>ارجاع شده</strong></li>
            <li>• عملیات: <strong>پذیرش</strong>، <strong>ارجاع به واحد دیگر</strong>، <strong>تکمیل و بستن</strong></li>
            <li>• اضافه کردن یادداشت و ضمیمه در هر مرحله</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-eye" class="w-5 h-5 text-secondary" />
            نظارت (Monitoring) - تیکت‌های واحد شما
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• تمام تیکت‌های مربوط به واحد شما (ایجادشده یا ارجاعشده)</li>
            <li>• فیلتر بر اساس وضعیت، اولویت، تخصیص به من</li>
            <li>• نمای جدول با جزئیات کامل</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-arrow-path" class="w-5 h-5 text-warning" />
            گردش کار تیکت (Workflow)
        </h4>
        <div class="flex flex-wrap gap-1 text-xs">
            <span class="badge badge-primary">جدید (Created)</span>
            <span class="badge badge-info">پذیرفته شده (Accepted)</span>
            <span class="badge badge-warning">ارجاع شده (Forwarded)</span>
            <span class="badge badge-success">تکمیل شده (Completed)</span>
            <span class="badge badge-error">رد شده (Rejected)</span>
        </div>
        <p class="text-sm text-base-content/70 mt-2">
            <strong>مسیری:</strong> جدید → پذیرفته شده → (ارجاع) → تکمیل شده
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-link" class="w-5 h-5 text-secondary" />
            ارتباط با وظایف (Tasks)
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• تیکت می‌تواند به یک وظیفه (Todo) متصل شود</li>
            <li>• نمایش وضعیت وظیفه در جزئیات تیکت</li>
            <li>• تسهیل پیگیری کارهای مرتبط</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• دسترسی بر اساس واحد سازمانی (شما و زیردمونه‌ها)</li>
            <li>• فقط واحد مقصد می‌تواند تیکت را بپذیرد یا ارجاع دهد</li>
            <li>• تکمیل تیکت نیازمند یادداشت نهایی است</li>
            <li>• تاریخچه کامل فعالیت‌ها در مودال جزئیات قابل مشاهده است</li>
        </ul>
    </div>
</div>