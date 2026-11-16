<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-wrench-screwdriver" class="w-5 h-5 text-primary" />
            زمان‌بندی تعمیر و نگهداری
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            برنامه‌های تکرارشوندهٔ نگهداری را تعریف کنید تا سیستم در سررسید هر برنامه به‌صورت خودکار تیکت بسازد.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-plus-circle" class="w-5 h-5 text-success" />
            ایجاد برنامه
        </h4>
        <ol class="space-y-2 text-sm text-base-content/70 list-decimal list-inside">
            <li>دکمهٔ «برنامه نگهداری جدید» فرم ساخت برنامه را باز می‌کند.</li>
            <li>«عنوان» را وارد کنید؛ همین عنوان، موضوع تیکت خودکار خواهد بود.</li>
            <li>«دوره» را روی «روزانه»، «هفتگی» یا «ماهانه» بگذارید و در فیلد «هر» فاصلهٔ تکرار را مشخص کنید (مثلاً دورهٔ ماهانه با «هر» = ۲ یعنی هر دو ماه).</li>
            <li>«واحد» را انتخاب کنید؛ واحد در این فرم اجباری است و فقط واحدهای در محدودهٔ شما فهرست می‌شوند.</li>
            <li>«ذخیره» را بزنید؛ «سررسید بعدی» به‌طور خودکار از امروز به‌اضافهٔ دوره محاسبه و در جدول نمایش داده می‌شود.</li>
        </ol>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-clock" class="w-5 h-5 text-info" />
            سررسید و تیکت خودکار
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• جاب شبانهٔ <span class="font-mono" dir="ltr">maintenance:generate-due</span> هر روز ساعت ۰۳:۰۰ اجرا می‌شود.</li>
            <li>• برای هر برنامه‌ای که «سررسید بعدی» آن رسیده باشد، یک تیکت با همان عنوان و در همان واحد در صندوق تیکت‌ها می‌سازد.</li>
            <li>• سپس «سررسید بعدی» همان برنامه را به دورهٔ بعد جلو می‌برد تا برنامه دوباره در نوبت بعدی برسد.</li>
            <li>• اگر تیکت غیرتکمیل‌شده‌ای با همان عنوان و همان واحد که در ۲۴ ساعت گذشته ساخته شده باشد، تیکت دوم ساخته نمی‌شود و فقط سررسید جلو می‌رود — این محافظ، پنجرهٔ ۲۴ ساعته دارد.</li>
            <li>• تیکت بازِ قدیمی‌تر از ۲۴ ساعت مانع نیست؛ در اجرای بعدی تیکت تازه ساخته می‌شود.</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-pencil-square" class="w-5 h-5 text-warning" />
            ویرایش و حذف
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• در ستون «عنوان»، آیکن مداد عنوان را در همان ردیف قابل ویرایش می‌کند؛ با Enter یا آیکن تأیید ذخیره و با آیکن لغو رها می‌شود.</li>
            <li>• آیکن سطل زباله برنامه را با پیام تأیید «آیا مطمئن هستید؟» حذف می‌کند؛ حذف بازگشت‌پذیر نیست.</li>
            <li>• ویرایش ردیف تنها «عنوان» را تغییر می‌دهد؛ برای دوره، فاصله یا واحد دیگر، برنامهٔ جدید بسازید و برنامهٔ قبلی را حذف کنید.</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-check-circle" class="w-5 h-5 text-primary" />
            وضعیت
        </h4>
        <p class="text-sm text-base-content/70">
            ستون «وضعیت» بر اساس «سررسید بعدی» تعیین می‌شود: تا وقتی سررسید نرسیده برچسب «فعال» و پس از رسیدن آن برچسب «سررسید شده» نمایش داده می‌شود.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• این صفحه با مجوز <span class="font-mono" dir="ltr">manage_hardware</span> باز می‌شود و ساخت، ویرایش و حذف هم همان مجوز را می‌خواهند.</li>
            <li>• خواندن و نوشتن محدود به واحدهای در دسترس شماست و واحدِ ارسالی فرم دوباره اعتبارسنجی می‌شود؛ واحد خارج از دسترس پذیرفته نمی‌شود.</li>            <li>• اگر واحد یک برنامه حذف شود، واحدِ برنامه خالی می‌شود و جاب شبانه آن را رد می‌کند تا دوباره واحد بگیرد؛ برنامهٔ بدون واحد تیکت نمی‌سازد.</li>
        </ul>
    </div>
</div>
