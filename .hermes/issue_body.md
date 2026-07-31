## مشکل

کامپوننت Livewire صفحه سخت‌افزار (`resources/views/livewire/hardware/index.blade.php`) عملیات Bulk را بدون فیلتر دسترسی سازمانی اجرا می‌کند. این یعنی کاربری که مجوز `manage_hardware` دارد می‌تواند رکوردهای متعلق به واحدهای سازمانی دیگر را حذف یا ویرایش کند.

## شواهد

### `bulkMark()` (خطوط 239-249):
```php
public function bulkMark(bool $value): void
{
    if (empty($this->selected)) {
        $this->error('هیچ ردیفی انتخاب نشده است.', position: 'toast-bottom');
        return;
    }

    Hardware::whereIn('id', $this->selected)->update(['mark' => $value]);
    // ↑ بدون فیلتر دسترسی سازمانی!
    $this->selected = [];
    $this->success('وضعیت علامت‌گذاری تغییر کرد.', position: 'toast-bottom');
}
```

### `bulkDelete()` (خطوط 251-257):
```php
public function bulkDelete(): void
{
    if (empty($this->selected)) return;
    Hardware::whereIn('id', $this->selected)->delete();
    // ↑ بدون فیلتر دسترسی سازمانی!
    $this->selected = [];
    $this->warning('دستگاه‌های انتخاب شده حذف شدند.', position: 'toast-bottom');
}
```

### مقایسه با API کنترلر (که درست کار می‌کند):
فایل `app/Http/Controllers/Api/HardwareController.php` خطوط 241-266 فیلتر دسترسی سازمانی را اعمال می‌کند:
```php
$count = Hardware::whereIn('id', $request->ids)
    ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
    ->update(['mark' => $request->mark]);
```

اما کامپوننت Livewire هیچ فیلتری ندارد.

## مثال خطر

کاربری از واحد فناوری اطلاعات (واحد ۱) که دستگاه‌های لیست شده در UI را می‌بیند، می‌تواند دستگاه‌های واحد ۲ (بیمارستان) را انتخاب کرده و حذف یا علامت‌گذاری کند. چون `selected` فقط array از `id` است و بدون بررسی دسترسی سازمانی به دیتابیس اعمال می‌شود.

## راه‌حل پیشنهادی

۱. تزریق `AccessService` درون کامپوننت.
۲. قبل از BulkMark/BulkDelete، شناسه‌های قابل دسترس را فیلتر کرد:
```php
$accessibleIds = app(\App\Services\AccessService::class)->accessibleUnitIds();
$scopedIds = Hardware::whereIn('id', $this->selected)
    ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
    ->pluck('id')
    ->toArray();
```
۳. اعمال عملیات فقط روی `$scopedIds`.

## تخمین تلاش

**Small (S)** — حداکثر ۱۵ خط تغییر در یک فایل. الگوی اصلاحی دقیقاً در `HardwareController@bulkMark` و `@bulkDelete` موجود است و کافیست در کامپوننت Livewire تکرار شود.