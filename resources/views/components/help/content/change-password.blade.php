<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-key" class="w-5 h-5 text-primary" />
            تغییر رمز ورود
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            از این صفحه رمز حساب خودتان را عوض می‌کنید. رمز فعلی لازم است تا کسی که پشت سیستم نشسته نتواند بدون دانستن آن رمز را تغییر دهد.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-check-circle" class="w-5 h-5 text-info" />
            فرم تغییر رمز
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>رمز فعلی:</strong> همان رمزی که الآن با آن وارد می‌شوید</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>رمز جدید:</strong> حداقل ۸ کاراکتر و باید با رمز فعلی فرق داشته باشد</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>تأیید رمز جدید:</strong> باید دقیقاً با رمز جدید یکی باشد</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> دکمهٔ «تغییر رمز» فرم را می‌فرستد و دکمهٔ «ریست» فقط فرم را خالی می‌کند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> خطاها بالای فرم در کادر «خطا» فهرست می‌شوند</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-warning" />
            خطاهای رایج
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> «رمز فعلی اشتباه است» یعنی رمزی که در فیلد اول زده‌اید با رمز حساب نمی‌خواند؛ هیچ چیز تغییر نکرده است</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> «... باید متفاوت باشند» یعنی رمز تازه را همان رمز قبلی گذاشته‌اید</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> پیام کوتاه بودن رمز یعنی کمتر از ۸ کاراکتر وارد شده است</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> ناهمخوانی «رمز جدید» و «تأیید رمز جدید» هم همان‌جا گزارش می‌شود و فرم ارسال نمی‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-shield-check" class="w-5 h-5 text-success" />
            پس از تغییر رمز
        </h4>
        <ul class="space-y-2 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> پیام «رمز با موفقیت تغییر یافت» یعنی رمز ذخیره شده است؛ همین نشست باز می‌ماند و لازم نیست دوباره وارد شوید</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> نشست‌های وب روی دستگاه‌های دیگر بسته می‌شوند؛ آن دستگاه‌ها باید با رمز تازه دوباره وارد شوند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> توکن‌های API هم باطل می‌شوند، پس اپ موبایل باید یک بار دیگر sign in کند</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> رمز تازه را جایی امن نگه دارید؛ بازیابی رمز از این صفحه انجام نمی‌شود</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• برای امنیت بیشتر رمز تازه را با رمز سایر سرویس‌ها یکی نگذارید</li>
            <li>• اگر رمز فعلی را اشتباه بزنید هیچ چیز تغییر نمی‌کند و همان پیام خطا را می‌گیرید</li>
            <li>• پس از تغییر رمز، اگر دستگاهی بیرون افتاد، با رمز تازه دوباره وارد شوید؛ راه دیگری لازم نیست</li>
        </ul>
    </div>
</div>
