<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-arrow-down-tray" class="w-5 h-5 text-primary" />
            ورود اطلاعات پرسنل از فایل اکسل
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            با استفاده از این بخش می‌توانید اطلاعات پرسنل را به صورت دسته‌جمعی از فایل اکسل (xlsx/xls) یا CSV وارد سیستم کنید.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-document-text" class="w-5 h-5 text-success" />
            فرمت فایل
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 list-disc list-inside">
            <li>فرمت‌های پشتیبانی‌شده: <code class="px-1 bg-base-200 rounded">.xlsx</code>، <code class="px-1 bg-base-200 rounded">.xls</code>، <code class="px-1 bg-base-200 rounded">.csv</code> — حداکثر ۱۰ مگابایت</li>
            <li>ستون‌های الزامی: <code class="px-1 bg-base-200 rounded">n_code</code> (کد ملی)، <code class="px-1 bg-base-200 rounded">f_name</code> (نام)، <code class="px-1 bg-base-200 rounded">l_name</code> (نام خانوادگی)</li>
            <li>ستون‌های تکمیلی: <code class="px-1 bg-base-200 rounded">t_id</code> (تحصیلات)، <code class="px-1 bg-base-200 rounded">e_id</code> (نوع استخدام)، <code class="px-1 bg-base-200 rounded">s_id</code> (سمت)، <code class="px-1 bg-base-200 rounded">r_id</code> (ردیف)، <code class="px-1 bg-base-200 rounded">u_id</code> (واحد سازمانی)</li>
            <li>مطابقت و تشخیص رکوردهای تکراری بر اساس <strong>کد ملی (n_code)</strong> انجام می‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-eye" class="w-5 h-5 text-info" />
            پیش‌نمایش و تأیید
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• پس از آپلود، پیش‌نمایش رکوردها نمایش داده می‌شود</li>
            <li>• رکوردهای جدید، به‌روزرسانی‌شده و بدون تغییر مشخص می‌شوند</li>
            <li>• قبل از ذخیره نهایی، لیست را بررسی و تأیید کنید</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• تمام رکوردهای واردشده باید در محدوده واحدهای قابل‌دسترسی شما باشند</li>
            <li>• کد ملی تکراری به‌روزرسانی می‌شود، نه ثبت مجدد</li>
            <li>• حروف فارسی/عربی به‌طور خودکار نرمال‌سازی می‌شوند (ي/ك → ی/ک)</li>
        </ul>
    </div>
</div>