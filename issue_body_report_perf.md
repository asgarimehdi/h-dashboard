**مشکل (Problem):**

در متدهای `todos()` و `tickets()` در فایل `app/Http/Controllers/Api/ReportController.php`، aggregation گروه‌بندی بر اساس تاریخ و واحد در **PHP** انجام می‌شود نه در دیتابیس. تمام رکوردها به حافظه بارگذاری می‌شوند و سپس با `groupBy` روی Collection در PHP گروه‌بندی می‌شوند — به جای اینکه دیتابیس این کار را با `GROUP BY` انجام دهد.

**محل کد (File:Line):**

1. `app/Http/Controllers/Api/ReportController.php:52-61` (بخش `byDay` در todos):
```php
$byDay = (clone $query)
    ->get()                                              // بارگذاری همه رکوردها در PHP
    ->groupBy(fn ($r) => $r->day)
    ->map(fn ($items) => $items->count())
    ->toArray();
```

2. `app/Http/Controllers/Api/ReportController.php:63-68` (بخش `byUnit` در todos):
```php
$byUnit = (clone $query)
    ->with('unit:id,name')                               // Eager load
    ->get()                                              // بارگذاری همه رکوردها در PHP
    ->groupBy(fn ($t) => $t->unit?->name ?? 'نامشخص')
    ->map(fn ($items) => $items->count())
    ->toArray();
```

**تأثیر (Impact):**

- اگر ۱۰,۰۰۰ todo وجود داشته باشد، همه آنها ابتدا در PHP بارگذاری می‌شوند تا گروه‌بندی شوند — مصرف حافظه بالا
- با رشد داده‌ها، زمان پاسخ و مصرف حافظه به صورت **خطی** افزایش می‌یابد
- در تست‌ها دیده نمی‌شود چون dataset تست کوچک است
- مقایسه: `byStatus` و `byPriority` در `tickets()` از قبل بهینه هستند (استفاده از `selectRaw + groupBy` در دیتابیس)

**پیشنهاد رفع (Suggested Fix):**

جایگزینی aggregation مبتنی بر PHP با aggregation در سطح دیتابیس:

برای `byDay` در todos:
```php
$byDay = (clone $query)
    ->selectRaw("date(start_at) as day, count(*) as count")
    ->groupBy('day')
    ->orderBy('day')
    ->get()
    ->map(fn ($r) => [
        'day' => Jalalian::fromCarbon(Carbon::parse($r->day))->format('Y/m/d'),
        'count' => (int) $r->count,
    ])
    ->toArray();
```

برای `byUnit` در todos:
```php
$byUnit = Todo::selectRaw('COALESCE(units.name, ?) as unit_name, COUNT(*) as count', ['نامشخص'])
    ->whereIn('unit_id', $accessibleIds)
    ->join('units', 'todos.unit_id', '=', 'units.id')
    ->groupBy('unit_name')
    ->pluck('count', 'unit_name')
    ->toArray();
```

**تخمین تلاش (Effort):** S (کوچک — ۳-۴ تغییر خط، تست با همین تست‌های موجود)

**ملاحظات:**
- فرمت تاریخ شمسی (Jalalian::fromCarbon) روی نتیجه aggregation اعمال می‌شود، همانند روش فعلی
- توسط byStatus و byPriority در tickets() از قبل بهینه هستند و نیاز به تغییر ندارند
- تست‌های فعلی باید این تغییرات را پوشش دهند
