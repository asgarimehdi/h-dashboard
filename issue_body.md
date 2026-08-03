## شرح مشکل

در کامپوننت `ActivityLog` (فایل `resources/views/livewire/activity-log/index.blade.php`)، هنگام نمایش لیست فعالیت‌ها، برای هر ردیف، رابطه کاربر (`user`) فراخوانی می‌شود. اگرچه در متد `logs()` از `with('user')` استفاده شده است، اما در بخش نمایش جزئیات (`showDetail`) و همچنین در بخش `typeStats` و `getAccessibleUserIds` کوئری‌های تکراری زده می‌شود.

به طور خاص در متد `logs()`:
```php
$query = ActivityLog::with("user")
    ->whereIn("user_id", $accessibleUserIds);
```

اما در بخش نمایش جدول و `showDetail` دوباره `with('user')` صدا زده می‌شود که باعث افزایش تعداد کوئری‌ها می‌شود.

همچنین متد `getAccessibleUserIds()` در هر بار فراخوانی `logs()` (که در هر رندر Livewire اتفاق می‌افتد) یک کوئری سنگین روی جدول کاربران می‌زند:
```php
return \App\Models\User::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleUnitIds))
    ->pluck('id')
    ->toArray();
```
این مقدار می‌تواند کش شود یا به صورت Computed Property بهینه‌تر مدیریت شود.

## شواهد

فایل: `resources/views/livewire/activity-log/index.blade.php`
خطوط ۷۷-۸۰ و ۵۱-۵۷.

## تاثیر

کاهش سرعت لود صفحه گزارش فعالیت‌ها با افزایش تعداد رکوردها و کاربران در سیستم.

## پیشنهاد اصلاح

۱. کش کردن نتیجه `getAccessibleUserIds()` برای مدت کوتاهی (مثلاً ۵ دقیقه) مشابه سایر آمارهای داشبورد.
۲. اطمینان از اینکه تمام دسترسی‌ها به رابطه‌ها در `logs()` و `showDetail()` بهینه‌سازی شده‌اند.

## تخمین زمان

کم (Low)