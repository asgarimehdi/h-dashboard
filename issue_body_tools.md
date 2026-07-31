## مشکل
صفحه ابزارهای مدیریتی (`/tools`) در هر بار لود (mount) **۶ کوئری COUNT جداگانه** اجرا می‌کند که همگی **بدون کش** هستند:

```php
// resources/views/livewire/tools/tools.blade.php (خطوط 19-29)
// app/Http/Controllers/ToolsController.php (خطوط 19-29)
$this->stats = [
    'old_tickets'        => Ticket::whereIn(...)->count(),           // ۱
    'old_activities'     => ActivityLog::where(...)->count(),        // ۲ - بدون Organizational Scope!
    'old_notifications'  => Notification::where(...)->count(),       // ۳ - بدون Organizational Scope!
    'total_tickets'      => Ticket::whereIn(...)->count(),           // ۴
    'total_activities'   => ActivityLog::count(),                    // ۵ - بدون Organizational Scope!
    'total_notifications'=> Notification::count(),                   // ۶ - بدون Organizational Scope!
];
```

## اثبات (فایل: خط)
- `resources/views/livewire/tools/tools.blade.php:19-29` — Livewire component mount()
- `app/Http/Controllers/ToolsController.php:19-29` — Controller index() (کد تکراری!)

## تأثیر
- **هر بازدید ادمین از `/tools` = ۶ کوئری COUNT** روی جداول بزرگ (`activity_logs`, `notifications`)
- جداول `activity_logs` و `notifications` می‌توانند میلیون‌ها رکورد داشته باشند → `COUNT(*)` بدون شرط `where` اسکن کامل جدول (Full Table Scan)
- **عدم Organizational Scope** در `ActivityLog` و `Notification`: آمار کل سیستم بدون محدودیت واحدهای کاربر
- کد تکراری بین Livewire Component و Controller → نگهداری دشوار

## راه‌حل پیشنهادی
1. **افزودن کش با TTL ۶۰ ثانیه** برای تمام آمارها (آمارها نیازی به Real-time ندارند)
2. **اعمال Organizational Scope** روی `ActivityLog` و `Notification` (یا مستند کردن دلیل عدم اعمال)
3. **حذف کد تکراری**: منتقل کردن منطق به یک Service کلاس مشترک (مثلاً `ToolsStatsService`)
4. استفاده از `Cache::remember()` با کلید شامل `accessibleUnitIds` برای جدا کردن کش بر اساس محدوده سازمانی

```php
// پیشنهادی
$accessibleIds = app(AccessService::class)->accessibleUnitIds();
$cacheKey = 'tools:stats:' . md5(implode(',', $accessibleIds));

$this->stats = Cache::remember($cacheKey, 60, function () use ($accessibleIds) {
    return [
        'old_tickets'        => Ticket::whereIn('unit_id', $accessibleIds)->where(...)->count(),
        'old_activities'     => ActivityLog::whereIn('unit_id', $accessibleIds)->where(...)->count(),
        'old_notifications'  => Notification::whereIn('user_id', $userIds)->where(...)->count(),
        'total_tickets'      => Ticket::whereIn('unit_id', $accessibleIds)->count(),
        'total_activities'   => ActivityLog::whereIn('unit_id', $accessibleIds)->count(),
        'total_notifications'=> Notification::whereIn('user_id', $userIds)->count(),
    ];
});
```

## تخمین تلاش
**S (کوچک)** — فقط اضافه کردن Cache::remember، refactor به Service مشترک، و اضافه کردن scope‌های مورد نیاز. بدون تغییر دیتابیس یا UI.
