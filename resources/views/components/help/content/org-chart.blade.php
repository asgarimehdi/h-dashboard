<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-building-office-2" class="w-5 h-5 text-primary" />
            چارت سازمانی
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            این صفحه سلسله‌مراتب واحدهای سازمانی را به شکل درخت نشان می‌دهد. سمت راست درخت سازمانی است و سمت چپ کارت «جزئیات واحد» که با انتخاب هر واحد پر می‌شود.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-magnifying-glass" class="w-5 h-5 text-info" />
            درخت سازمانی
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>جستجو:</strong> کادر «جستجوی واحد...» واحدهای مطابق را پیدا می‌کند و مسیر تا والدها را باز می‌کند تا نتیجه پنهان نماند؛ واحدهای مطابق با حاشیهٔ رنگی مشخص می‌شوند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>باز و بسته کردن:</strong> دکمه‌های «باز کردن همه» و «جمع کردن» کل درخت را یک‌جا باز یا جمع می‌کنند؛ با دکمهٔ +/− کنار هر گره هم فقط همان شاخه باز یا بسته می‌شود</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>بارگذاری تدریجی:</strong> سطح‌های اولیه باز هستند و فرزندان هر شاخه فقط وقتی بازش کنید از سرور خوانده می‌شوند؛ همین درخت را سبک نگه می‌دارد</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>کلیک روی گره:</strong> با انتخاب هر واحد، کارت جزئیات همان واحد پر می‌شود؛ کلیک روی +/− انتخاب را عوض نمی‌کند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>بج پرسنل:</strong> کنار هر گره تعداد پرسنل مستقیم همان واحد نوشته شده است؛ واحد بدون پرسنل بج «خالی» می‌گیرد</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-user-group" class="w-5 h-5 text-warning" />
            جزئیات واحد
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>نوع و والد:</strong> نوع واحد و واحد بالادست آن؛ اگر والد نداشته باشد «---» نشان داده می‌شود</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>پرسنل:</strong> تعداد پرسنل مستقیم و در پرانتز تعداد پرسنل همهٔ زیرمجموعه‌ها</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>کاربران:</strong> تعداد کاربران مستقیم واحد و در پرانتز تعداد کاربران زیرمجموعه‌ها</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>فهرست پرسنل:</strong> ۲۰ نفر اول پرسنل واحد با سمت هر نفر؛ آیکن سبز یعنی حساب کاربری دارد و آیکن قرمز یعنی ندارد</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> اگر واحدی انتخاب نشده باشد، کارت کناری پیام «یک واحد را انتخاب کنید» را نشان می‌دهد</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-identification" class="w-5 h-5 text-primary" />
            خواندن آمار
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> بج کنار گره فقط پرسنل <strong>مستقیم</strong> همان واحد را می‌شمارد؛ جمع زیرمجموعه را در کارت «جزئیات واحد» ببینید</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> جستجو بج‌ها را عوض نمی‌کند؛ بج همیشه کل پرسنل آن واحد است، نه فقط نتیجهٔ جستجو</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> این صفحه فقط نمایش است؛ آمار پس از هر تغییر در پرسنل یا واحدها، با بارگذاری دوباره به‌روز می‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• دامنهٔ نمایش همان واحدهای در دسترس شما و همهٔ فرزندانشان است؛ واحد بیرون از این دامنه در درخت نمی‌آید و انتخاب مستقیمش هم پیام خطا می‌دهد</li>
            <li>• داده‌ها از درخت سازمانی خوانده می‌شوند؛ اگر واحدی را جابه‌جا کردید، همین‌جا در جای تازه‌اش دیده می‌شود</li>
            <li>• آمار «زیرمجموعه» همیشه همهٔ نسل‌های پایین‌تر را می‌شمارد، نه فقط فرزندان مستقیم</li>
            <li>• ورود به این صفحه مجوز «مدیریت چارت سازمانی» یا «مشاهدهٔ داشبورد منابع انسانی» می‌خواهد</li>
        </ul>
    </div>
</div>
