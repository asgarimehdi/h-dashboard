<div class="p-4">
    <h2 class="text-xl font-bold mb-4 text-primary">راهنمای مدیریت زابیکس</h2>

    <div class="space-y-6">
        <section>
            <h3 class="text-lg font-semibold text-primary mb-2">نمای کلی</h3>
            <p class="text-base-content/80">
                ماژول مدیریت زابیکس امکان مدیریت کامل هاست‌ها، آیتم‌های مانیتورینگ و لینک‌های ترافیک را فراهم می‌کند.
                تمام تغییرات در دیتابیس ذخیره شده و از طریق API قابل مدیریت هستند.
            </p>
        </section>

        <section>
            <h3 class="text-lg font-semibold text-primary mb-2">هاست‌های زابیکس</h3>
            <ul class="list-disc list-inside space-y-1 text-base-content/80">
                <li><strong>افزودن هاست:</strong> شناسه هاست (Host ID)، نام، IP و واحد سازمانی را مشخص کنید</li>
                <li><strong>همگام‌سازی (Sync):</strong> دکمه Sync آیتم‌های مانیتورینگ را از Zabbix API دریافت و ذخیره می‌کند</li>
                <li><strong>کشف (Discover):</strong> لیست تمام آیتم‌های موجود در Zabbix برای هاست را نمایش می‌دهد</li>
                <li><strong>وضعیت:</strong> فعال، غیرفعال، تعمیرات</li>
            </ul>
        </section>

        <section>
            <h3 class="text-lg font-semibold text-primary mb-2">آیتم‌های مانیتورینگ</h3>
            <ul class="list-disc list-inside space-y-1 text-base-content/80">
                <li>نوع: ورودی/خروجی ترافیک، CPU، حافظه، دیسک، سفارشی</li>
                <li>مانیتورینگ فعال/غیرفعال برای نمایش در داشبوردها</li>
                <li>مقدار آخر (Last Value) و واحد اندازه‌گیری</li>
                <li>Sync دستی برای به‌روزرسانی مقادیر</li>
            </ul>
        </section>

        <section>
            <h3 class="text-lg font-semibold text-primary mb-2">لینک‌های ترافیک (جفت‌های In/Out)</h3>
            <ul class="list-disc list-inside space-y-1 text-base-content/80">
                <li>تعریف جفت‌های ورودی/خروجی برای نمایش ترافیک در داشبورد شبکه</li>
                <li>اعتبارسنجی: آیتم‌های In/Out باید به همان هاست تعلق داشته باشند</li>
                <li>نوع آیتم Out باید <code>traffic_out</code> و نوع In باید <code>traffic_in</code> باشد</li>
                <li>جایگزین لیست Hardcoded در صفحه شبکه‌ها</li>
            </ul>
        </section>

        <section>
            <h3 class="text-lg font-semibold text-primary mb-2">محدوده سازمانی (Scope)</h3>
            <ul class="list-disc list-inside space-y-1 text-base-content/80">
                <li>هاست‌های بدون واحد (unit_id = null) برای همه قابل مشاهده هستند</li>
                <li>هاست‌های با واحد فقط برای کاربران آن واحد قابل مشاهده‌اند</li>
                <li>اعتبارسنجی در ایجاد/ویرایش هاست، آیتم و جفت ترافیک</li>
            </ul>
        </section>

        <section>
            <h3 class="text-lg font-semibold text-primary mb-2">عملیات Bulk</h3>
            <ul class="list-disc list-inside space-y-1 text-base-content/80">
                <li><strong>Sync هاست:</strong> دریافت تمام آیتم‌ها از Zabbix API و ذخیره/به‌روزرسانی</li>
                <li><strong>Bulk Sync آیتم‌ها:</strong> دریافت آخرین مقادیر برای تمام آیتم‌های مانیتورشده</li>
                <li>دکمه Sync در لیست هاست‌ها و دکمه Bulk Sync در لیست آیتم‌ها</li>
            </ul>
        </section>
    </div>