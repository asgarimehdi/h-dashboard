## مشکل

کامپوننت **زنگوله نوتیفیکیشن** (`resources/views/livewire/notifications/bell.blade.php`) در هر بارگذاری و هر Poll حدود ۲ کوئری بدون کش اجرا می‌کند. این صفحه در **تمام صفحات** لود می‌شود و هر ~۲۵ ثانیه یکبار Polling می‌شود.

### ۱. دو کوئری بدون کش در `loadNotifications()`
```php
// خطوط ۱۹-۲۶
$this->notifications = Notification::where('user_id', auth()->id())
    ->latest()
    ->take(15)
    ->get()
    ->toArray();               // ← ۱) toArray() غیرضروری

$this->unreadCount = Notification::where('user_id', auth()->id())  // ← ۲) کوئری مجزا
    ->where('is_read', false)
    ->count();
```

### ۲. هیچ فیلتر فیلدی وجود ندارد
مدل `Notification` فیلدهای `url` و `data` را هم در query لود می‌کند در حالی که در view فقط `title`، `body`، `icon` و `created_at` نیاز است.

### ۳. `toArray()` باعث هدررفت memory می‌شود
`toArray()` مدل‌ها را به آرایه تبدیل می‌کند در حالیکه Livewire خودش سریالایز می‌کند.

---

## شواهد (فایل:خط)

| فایل | خط | مشکل |
|------|-----|------|
| `resources/views/livewire/notifications/bell.blade.php` | ۱۹-۲۳ | `loadNotifications()` بدون Cache + `toArray()` |
| `resources/views/livewire/notifications/bell.blade.php` | ۲۴-۲۶ | `unreadCount` کوئری مجزا بدون Cache |
| `resources/views/livewire/notifications/bell.blade.php` | — | هیچ فیلدی Select نشده — همه eager load می‌شوند |

---

## تاثیر

### عملکردی
- هر **Poll** (~۲۵ ثانیه): **۲ کوئری بدون کش** روی جدول `notifications`
- `toArray()`: تبدیل مدل‌ها به آرایه در هر poll → memory overhead
- با ۱۰۰+ نوتیفیکیشن، این تکرار زیاد می‌شود
- این صفحه در **تمام layoutها** لود می‌شود → impact گسترده

### الگوی مشابه که قبلاً اصلاح شده
الگوی کش برای notification قبلاً به‌صورت ترکیبی (`count` + `list`) پیاده شده:
- `dashboard.blade.php`: Cache برای stats با TTL متفاوت
- `ZoneMap.php`: Cache با TTL ۵ دقیقه برای zone list
- `Notification` model: `markAllAsRead()` متد دارد اما از Cache استفاده نمی‌کند

---

## راهکار پیشنهادی

### ۱. یکجا کردن دو کوئری با کش
```php
public function loadNotifications(): void
{
    $cacheKey = 'notifications:user:' . auth()->id();
    [$notifications, $unreadCount] = Cache::remember($cacheKey, 60, function () {
        $notifications = Notification::where('user_id', auth()->id())
            ->select('id', 'type', 'title', 'body', 'icon', 'color', 'url', 'is_read', 'created_at')
            ->latest()
            ->take(15)
            ->get();

        $unreadCount = Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();

        return [$notifications, $unreadCount];
    });

    $this->notifications = $notifications;
    $this->unreadCount = $unreadCount;
}
```

### ۲. اینتگریت Cache با عملیات Mark As Read
```php
public function markAsRead($id): void
{
    Notification::where('id', $id)
        ->where('user_id', auth()->id())
        ->update(['is_read' => true, 'read_at' => now()]);

    Cache::forget('notifications:user:' . auth()->id());
    $this->loadNotifications();
}

public function markAllAsRead(): void
{
    Notification::where('user_id', auth()->id())
        ->where('is_read', false)
        ->update(['is_read' => true, 'read_at' => now()]);

    Cache::forget('notifications:user:' . auth()->id());
    $this->loadNotifications();
}
```

### ۳. حذف `toArray()`
آرایه را مستقیم بدون `toArray()` به Livewire بده — Livewire خودش serialize می‌کند.

---

## برآورد تلاش

**S (کم)** — فقط یک فایل (~۱۵ خط تغییر). Cache key convention و الگوی قبلاً در کدبیس وجود دارد (مشابه `dashboard.blade.php`، `ZoneMap.php` و `county.blade.php`).
