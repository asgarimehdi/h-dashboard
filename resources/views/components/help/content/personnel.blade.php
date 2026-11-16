<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-user-group" class="w-5 h-5 text-primary" />
            پرسنل و کارگوزینی
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            مدیریت اطلاعات پرسنل، سوابق شغلی، سمت‌ها، تحصیلات، استخدام‌ها و ردیف‌ها.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-user-plus" class="w-5 h-5 text-success" />
            ثبت پرسنل جدید
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70 list-disc list-inside">
            <li>کد ملی (منحصر به فرد) - الزامی</li>
            <li>نام و نام خانوادگی - الزامی</li>
            <li>واحد سازمانی - الزامی</li>
            <li>سمت، تحصیلات، نوع استخدام، ردیف - الزامی</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-magnifying-glass" class="w-5 h-5 text-info" />
            جستجو و فیلتر
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• جستجوی همزمان در کد ملی، نام، نام خانوادگی</li>
            <li>• فیلتر بر اساس واحد سازمانی</li>
            <li>• فیلتر بر اساس سمت</li>
            <li>• مرتب‌سازی بر اساس فیلدها</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-pencil-square" class="w-5 h-5 text-warning" />
            ویرایش و حذف پرسنل
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• کلیک روی آیکون مداد برای ویرایش</li>
            <li>• کلیک روی آیکون سطل زباله برای حذف (با تأیید)</li>
            <li>• نرمال‌سازی خودکار حروف فارسی/عربی در هنگام ذخیره</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-list-bullet" class="w-5 h-5 text-secondary" />
            مدیریت داده‌های مرجع (چهار صفحهٔ جداگانه)
        </h4>
        <div class="grid grid-cols-2 gap-2 text-sm mb-3">
            <span class="badge badge-outline">سمت‌ها</span>
            <span class="badge badge-outline">تحصیلات</span>
            <span class="badge badge-outline">نوع استخدام</span>
            <span class="badge badge-outline">ردیف سازمانی</span>
        </div>
        <p class="text-sm text-base-content/70 mb-2">هر چهار صفحه یک الگوی واحد دارند:</p>
        <ol class="space-y-2 text-sm text-base-content/70 list-decimal list-inside">
            <li>دکمهٔ سبز بالای جدول («… جدید») فرم افزودن را باز می‌کند؛ عنوان را بنویسید و «ذخیره» بزنید</li>
            <li>آیکون مداد، همان سطر را به ورودی ویرایش تبدیل می‌کند؛ با تیک ذخیره یا با Enter ثبت می‌شود و با ضربدر لغو</li>
            <li>عنوان تکراری پذیرفته نمی‌شود و پیام خطا زیر همان ورودی نمایش داده می‌شود</li>
            <li>کادر جستجو فهرست را زنده فیلتر می‌کند؛ ستون‌ها قابل مرتب‌سازی و صفحه‌بندی ۱۰/۲۰/۵۰ ردیفی است</li>
            <li>آیکون سطل زباله با تأیید حذف می‌کند</li>
        </ol>
        <p class="text-sm text-base-content/70 mt-2">
            اگر یک ردیف در پروندهٔ پرسنل استفاده شده باشد، حذف رد می‌شود و پیام
            «امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است» نمایش داده می‌شود.
            واحدها و ناحیه‌ها جزو این چهار جدول نیستند و از تنظیمات مدیریت می‌شوند.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• کد ملی باید منحصر به فرد باشد (Unique)</li>
            <li>• پرسنل باید به واحدی اختصاص یابد که شما به آن دسترسی دارید</li>
            <li>• داده‌های مرجع (سمت، تحصیلات، ...) در تمام فرم‌ها مشترک هستند</li>
            <li>• ورود به این بخش به مجوز <code class="badge badge-ghost badge-xs">kargozini</code> نیاز دارد</li>
            <li>• حذف داده مرجعِ ارجاع‌شده ممکن نیست؛ ابتدا ارجاع‌ها را بردارید</li>
            <li>• جستجو از نرمال‌سازی فارسی/عربی پشتیبانی می‌کند</li>
        </ul>
    </div>
</div>
