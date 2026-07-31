## مشکل
در کامپوننت **Point Map** (`resources/views/livewire/maps/point.blade.php`)، متد `fetchLocation()` که در هر تغییر فیلتر (انتخاب شهرستان/نوع واحد) اجرا می‌شود، **۳ کوئری توالی** اجرا می‌کند که:
1. **بدون کش** هستند (در هر درخواست اجرا می‌شوند)
2. **بدون فیلتر Organizational Scope** (AccessService) — تمام واحدهای با مختصات برمی‌گرداند، نه فقط واحدهای قابل‌دسترسی کاربر
3. **پترن ناکارآمد:** دو بار کوئری `units` اجرا می‌کند (یک بار برای دریافت زیرمجموعه‌ها، یک بار برای دریافت با والدین)

### فایل و متد
- **فایل:** `resources/views/livewire/maps/point.blade.php`
- **متد:** `fetchLocation()` (خطوط ۳۶-۷۸)
- **فراخوانی‌ها:** در `mount()`، `updatedSelectedRegions()`، `updatedSelectedTypes()`

### جزئیات کوئری‌ها
```php
// کوئری ۱: واحدهای پایه با LIMIT 2000 (بدون scope کاربر)
$baseUnits = Unit::query()
    ->whereNotNull('lat')->whereNotNull('lng')
    ->whereIn('region_id', $selectedRegions)   // optional
    ->whereIn('unit_type_id', $selectedTypes) // optional
    ->limit(2000)
    ->select(['id','name','lat','lng','unit_type_id','parent_id'])
    ->get();

// کوئری ۲: دریافت ID والدین (برای خطوط اتصال)
$ancestorIds = Unit::whereIn('id', $baseIds)
    ->whereNotNull('parent_id')
    ->pluck('parent_id')
    ->toArray();

// کوئری ۳: اجرای مجدد کوئری مشابه با IDهای کامل (پایه + والدین)
$this->location = Unit::whereIn('id', $allIds)
    ->whereNotNull('lat')->whereNotNull('lng')
    ->select([...])
    ->get()->toArray();
```

## اثبات و دلایل
1. **۳ کوئری در هر تغییر فیلتر:** کاربر وقتی checkbox یک شهرستان را tick می‌کند، ۳ کوئری توالی اجرا می‌شوند (بدون کش، بدون pagination واقعی)
2. **LIMIT 2000 تصادفی:** ممکن است داده‌های مهم حذف شوند یا عملکرد کاهش یابد اگر واحدها > 2000 باشند
3. **بدون Organizational Scope:** `AccessService::accessibleUnitIds()` استفاده نشده — کوئری روی کل دیتابیس اجرا می‌شود
4. **تکرار کار:** کوئری ۳ تقریباً همان کوئری ۱ است با اضافه کردن ancestor IDs — می‌توان در حافظه ترکیب کرد
5. **سابقه در کدبیس:** کامپوننت `interactive.blade.php` (خطوط ۱۲-۳۰) الگوی مشابه دارد اما **با Scope** و در `mount()` فقط — `fetchLocation` رویداد کاربری است که پرکرارتر اجرا می‌شود

## تأثیر
- **دیتابیس:** ۳× کوئری در هر درخواست فیلتر (احتمالاً تعداد زیاد درین)
- **بار شبکه/CPU:** سریالیز کردن ۲۰۰۰ ردیف در حافظه PHP دو بار
- **امنیت/داده:** احتمالی نشت داده‌های واحدهای خارج از محدوده سازمانی کاربر
- **تجربه کاربری:** کندی محسوس در تغییر فیلترها (انتظار برای ۳ کوئری)

## پیشنهاد رفع
### ۱. اضافه کردن Organizational Scope (اولویت بالا)
```php
$accessibleIds = app(AccessService::class)->accessibleUnitIds();
$query->whereIn('id', $accessibleIds);
```

### ۲. کاهش به ۱ کوئری با Eager Loading
```php
public function fetchLocation(): void
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    
    $query = Unit::whereIn('id', $accessibleIds)
        ->whereNotNull('lat')->whereNotNull('lng');
    
    // Apply user filters
    if ($this->selectedRegions) $query->whereIn('region_id', $this->selectedRegions);
    if ($this->selectedTypes) $query->whereIn('unit_type_id', $this->selectedTypes);
    
    // Single query with limit + ancestors loaded
    $baseUnits = $query
        ->select(['id','name','lat','lng','unit_type_id','parent_id'])
        ->limit(5000) // افزایش یا حذف LIMIT با scope طبیعی
        ->get();
    
    // In-memory ancestor extraction (no extra DB query)
    $baseIds = $baseUnits->pluck('id')->toArray();
    $ancestorIds = $baseUnits
        ->where('parent_id', '!=', null)
        ->pluck('parent_id')
        ->unique()
        ->diff($baseIds)
        ->toArray();
    
    // Fetch ancestors in ONE additional query (not re-querying base units)
    $ancestors = Unit::whereIn('id', $ancestorIds)
        ->whereNotNull('lat')->whereNotNull('lng')
        ->select(['id','name','lat','lng','unit_type_id','parent_id'])
        ->get();
    
    $this->location = $baseUnits->concat($ancestors)->toArray();
    $this->dispatch('locations-updated', locations: $this->location);
}
```

### ۳. کش کردن Regions/Types در mount (مشابه Issue #180)
```php
$this->regions = Cache::remember('pointmap:regions', 300, fn() => 
    Region::whereNotIn('id', [1])->select('id','name')->get()->toArray());
$this->types = Cache::remember('pointmap:types', 300, fn() => 
    UnitType::whereNotIn('id', [1,2,3])->select('id','name')->get()->toArray());
```

## فایل و خطوط
- **فایل:** `resources/views/livewire/maps/point.blade.php`
- **خطوط:** ۳۶-۷۸ (متد `fetchLocation`)، ۱۸-۳۴ (متد `mount`)

## تخمین تلاش
**M (متوسط)** — تغییر منطق `fetchLocation`، اضافه کردن scope، کاهش کوئری‌ها، تست فیلترها، اضافه کردن کش در mount. ریسک متوسط (تغییر منطق query/collection، نیاز به تست تطبیق خروجی با حال حاضر).