# 0001 — معماری کلی h-dashboard

- **وضعیت:** فعال
- **تاریخ:** 2026-10-08
- **اسکن:** ۷,۱۷۸ نود / ۳۱,۶۳۱ یال (`codebase-memory`, حالت `full`)

## منبع حقیقت

این سند **شکل ساختاری** کد را ثبت می‌کند. **نیت** و قراردادها در `AGENTS.md` هستند
(کنترل دسترسی، جدول gotchaها، ارجاع به issueها).

اگر این سند و `AGENTS.md` اختلاف داشتند، **`AGENTS.md` درست است** و این فایل منسوخ است.
هر ادعایی در این سند که به شمارنده‌های گراف تکیه کند، فقط برای تاریخ
۲۰۲۶-۱۰-۰۸ معتبر است — شمارنده‌ها با هر بار ایندکس دوباره تغییر می‌کنند.

## سیستم

Laravel 13 روی PHP ^8.4. رابط کاربری Livewire 4 + MaryUI/DaisyUI + Alpine.js،
کاملاً RTL و فارسی. دو مصرف‌کننده دارد: وب‌UI، و اپ موبایل Flutter (توکن Sanctum).
PostgreSQL 16 + PostGIS برای داده هندسی؛ Redis برای cache/session/queue.

اندازه‌ی ریپو: ۶۱ کامپوننت Livewire، ۱۰۰ فایل PHP زیر `app/`، ۱۹۲ فایل زیر `tests/`.

## لایه‌بندی

گراف یک تفکیک هسته/ورودیِ اکید نشان می‌دهد:

| لایه | نقش | fan-in | fan-out |
|---|---|---|---|
| `Policies` | core | 544 | **0** |
| `Models` | core | 159 | **0** |
| `Http` | core | 54 | **0** |
| `Services` | core | 48 | **0** |
| `Exports` | core | 40 | **0** |

`Http`، `Models`، `Services`، `Policies` و `Exports` هیچ‌یک به بالا (سمت view یا
تست) صدا نمی‌زنند. هر یال ورودی از یکی از این‌ها می‌آید: یک view لایو‌وایر،
یک تست، یک import، یک دستور کنسول، یک migration، یا یک seeder.

هیچ چرخه‌ای (cycle) در کد اول‌-party وجود ندارد. هر ۷ چرخه‌ی کشف‌شده داخل
دارایی‌های vendor/minify‌شده است (`full-calendar.min`، `highcharts`، `leaflet`).

## invariantهایی که باید حفظ شوند

این‌ها تصمیم‌های معماری‌اند، نه توصیف وضع موجود. نقض هر کدام یک باگ است، نه یک تغییر سلیقه‌ای.

### ۱. کنترل دسترسی در یک سرویس متمرکز است

`app/Services/AccessService.php` (`accessibleUnitIds`، `allUnitIds`) تنها تولیدکننده‌ی
unit scope است. مصرف‌کننده‌های شناخته‌شده: `UnitScopedRequest`، `HasOrganizationalScope`،
`HardwareIndexHelpers`، `PersonImport`، `HardwareImport`، `AccessibleTodo`،
`TicketCommentPolicy`.

این یک نقطه‌ی تنگه‌ی واقعی است — چیزی که باید حفظ شود. تمام باگ‌های اسکوپِ ثبت‌شده
(`#816`، `#819`، `#839`، `#849`) در **مصرف‌کننده‌ها** بودند که فراموش می‌کردند
`AccessService` را اعمال کنند، نه در خودِ سرویس.

قاعده‌ی همراه: اسکوپ خالی یعنی «در محدوده‌ی هیچ‌چیز»، **نه** «بدون محدودیت».
`whereIn` بدون شرط بنویسید (آرایه‌ی خالی به `0 = 1` کامپایل می‌شود)، نه
`when($ids, …)` که با مقدار falsy کل پیش‌شرط را حذف می‌کند. جزئیات در `AGENTS.md`.

### ۲. ابطال کش از طریق interface انجام می‌شود

`CacheInvalidationServiceInterface` (`remember`/`increment`/`getVersion`/`batch`/`cacheKey`)
طرح version-counter را ارائه می‌دهد. همه‌ی namespaceهای نسخه‌دار باید در
`PruneStaleCache::NAMESPACES` ثبت شوند، وگرنه کلیدهای کهنه هرگز باطل نمی‌شوند.

### ۳. ترابری Zabbix یک مقدار است، نه استثنا

`app/Services/Zabbix/`: `ZabbixClient` (interface) + `ZabbixResult` (value object)
+ `ServiceZabbixClient`. کنترلرها روی **نتیجه** شاخه می‌زنند و شکست را به
۵۰۳ نگاشت می‌کنند.

کنترلرها `ZabbixClient` را تزریق می‌کنند، نه `ZabbixService`. این مرز است که
شکست را قابل‌تست نگه می‌دارد؛ دور زدنش یعنی اتصال دوباره به یک سرور زنده.

### ۴. کامپوننت‌های Livewire تک‌فایلی‌اند

کلاس PHP به‌صورت یک anonymous class در بالای همان فایل Blade زندگی می‌کند
(`return new class extends Component { … };`). هیچ فایل `app/Livewire/*.php` وجود ندارد.

**پیامد عملی:** هیچ نگاشت `app/… → tests/…` وجود ندارد. ابزارهایی که برای
انتخاب خودکار تست از روی مسیر فایل کار می‌کنند، در این ریپو نمی‌توانند کار کنند.

## محدودیت‌های ابزار ایندکس

این بخش عمداً در سند نگه داشته شده، چون هرکس ایندکس را در گراف بخواند
باید بداند کجا دروغ می‌گوید.

**۱. نام‌های عمومی متد به یک نود جمع می‌شوند.** بزرگ‌ترین تله‌ی این ایندکس.
فراخوانی‌های حل‌نشده‌ی `create()` (مدل Eloquent، `Schema::create`، factory)
همه به تنها نود `create`یی که پارسر حل کرده بود نسبت داده شدند. نتیجه:
`TicketCommentPolicy::create` با fan-in برابر ۵۷۰ ظاهر می‌شود، در حالی که
«فراخوانانش» شامل `create_cache_table.php` است.

**پیامد:** fan-in فقط برای نام‌هایی در این ریپو **غیرمبهم** قابل اعتماد است —
`accessibleUnitIds`، `descendantIds`، `getApiTokenAbilities`. قبل از هر نتیجه‌گیری
درباره‌ی یک hotspot، با `grep` تأیید کنید.

**۲. استخراج route ناقص است.** ۲۰ route از حدود ۷۵ route واقعی. بعضی مسیرها با
query string ذخیره شده‌اند (`/api/reports/tickets?days=7`) و بیشتر handlerها `-` هستند.
**برای فهرست routeها از `php artisan route:list` استفاده کنید، نه از گراف.**
به‌خصوص: `/api/tickets*` و `/api/notifications*` اصلاً در گراف نیستند، پس ماتریس
ability مربوط به Sanctum را نمی‌شود از ایندکس تأیید کرد — مستقیم از `routes/api.php`
بررسی کنید.

**۳. پوشش parse ناقص است.** ۸ فایل `parse_partial` و ۱ فایل `parse_unusable`.
مهم‌ترین مورد اول:
`resources/views/livewire/hardware/import-hardware/import-hardware.blade.php` خطوط ۲۹۸–۳۰۲
(به‌دلیل تک‌فایلی بودن Livewire، این کلاس کلاس PHP است — با grep بخوانید).
و `resources/views/op/index.php` که در کل ۱۸۲۱ خطش پارس نشد.

**۴. CodeGraph و codebase-memory در پوشش تست هم‌داستان نیستند.**
CodeGraph گزارش داد `accessibleUnitIds` «no tests found within 3 caller hops».
نادرست بود: `tests/Feature/AccessServiceTest.php` وجود دارد و ۳۱ ارجاع به
`accessibleUnitIds` در `tests/` هست.

**نتیجه‌ی مشترک:** هیچ ادعای پوششی از هیچ ایندکسی را بدون تأیید با
`grep`/خواندن فایل قبول نکنید. نوبت اول همین اشتباه داده شد.

## چه چیزی را عمداً ثبت نکردیم

- **شماره‌ی issue و تاریخ commit** — در `AGENTS.md` و گیت هستند و کهنه می‌شوند.
- **آمار دقیق فایل‌ها** — هر بار با تغییر ریپو عوض می‌شود.
- **نقاط داغ بر پایه‌ی fan-in** — تا وقتی ابزار برای نام‌های عمومی
  جمع‌شدنی است، این اعداد گمراه‌کننده‌اند. برای پیدا کردن نقاط داغ واقعی
  از `grep` و شمارش ارجاع استفاده کنید.

## پیوندها

- `AGENTS.md` — قرارداد کنترل دسترسی، جدول gotchaها، شمارش تست‌ها
- `docs/agents/domain.md` — نحوه‌ی مصرف اسناد دامنه