<div class="space-y-6">
    <div>
        <h4 class="font-bold text-lg mb-3 flex items-center gap-2">
            <x-icon name="o-map" class="w-5 h-5 text-primary" />
            نقشه‌های تعاملی و تحلیل مکانی
        </h4>
        <p class="text-sm text-base-content/70 leading-relaxed">
            نمایش بصری داده‌های مکانی: واحدها، تجهیزات، مرزها و نقاط روی نقشه OpenStreetMap.
        </p>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-map" class="w-5 h-5 text-info" />
            لایه‌های نقشه
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>واحدها:</strong> نشانگرها (Markers) رنگی بر اساس نوع واحد</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>مرزها:</strong> پلیگون‌های جغرافیایی واحدها (GeoJSON)</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>سخت‌افزار:</strong> موقعیت دستگاه‌ها (اگر مختصات داشته باشند)</li>
            <li class="flex gap-2"><x-icon name="o-check" class="w-4 h-4 text-success flex-shrink-0 mt-0.5" /> <strong>نقطه‌ها:</strong> نقاط دلخواه ثبت‌شده توسط کاربران</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-cpu-chip" class="w-5 h-5 text-secondary" />
            سخت‌افزار روی نقشه
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• آیکون‌های مخصوص: <span class="badge badge-success badge-xs">لپ‌تاپ</span> <span class="badge badge-info badge-xs">سرور</span> <span class="badge badge-primary badge-xs">پی‌سی</span></li>
            <li>• رنگ‌بندی وضعیت: سبز (فعال) / خاکستری (خاموش) / زرد (علامت‌دار)</li>
            <li>• کلیک روی دستگاه → مودال جزئیات سریع</li>
            <li>• فیلتر: نوع دستگاه، وضعیت، واحد سازمانی</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-map-pin" class="w-5 h-5 text-warning" />
            مسیرها و مسیریابی
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• رسم مسیر بین دو نقطه</li>
            <li>• محاسبه فاصله و زمان تقریبی</li>
            <li>• نمایش مسیرهای ذخیره‌شده</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6">
        <h4 class="font-bold text-base mb-3 flex items-center gap-2">
            <x-icon name="o-magnifying-glass" class="w-5 h-5 text-primary" />
            جستجوی مکانی
        </h4>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li>• جستجوی آدرس/مکان (Nominatim/OpenStreetMap)</li>
            <li>• پیدا کردن واحدهای در شعاع مشخص</li>
            <li>• استخراج واحدهای داخل یک محدوده (Polygon)</li>
        </ul>
    </div>

    <div class="border-t border-base-200 pt-6 bg-info/5 p-4 rounded-lg">
        <h4 class="font-bold text-sm mb-2 flex items-center gap-2">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" />
            نکات مهم
        </h4>
        <ul class="space-y-1 text-xs text-base-content/70">
            <li>• واحدها باید مختصات جغرافیایی (lat/lng) داشته باشند تا روی نقشه نمایش داده شوند</li>
            <li>• مرزها (Boundary) برای گزارش‌های مکانی و جستجوی پلیگون ضروری است</li>
            <li>• داده‌های نقشه از OpenStreetMap بارگذاری می‌شوند (نیاز به اینترنت)</li>
            <li>• عملکرد نقشه در موبایل با لمس بهینه‌سازی شده است</li>
        </ul>
    </div>
</div>