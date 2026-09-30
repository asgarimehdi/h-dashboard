# پلن اجرایی #755 — تریاژ ایشوهای #734 تا #744 + اجرای باقی‌مانده‌ها

> Written against commit: `58e9b34` (beta کانونیکال = origin/beta = برنچ `sydney` این سرور)
> Date: 2026-09-30
> Source spec: بدنهٔ ایشوی [#755](https://github.com/asgarimehdi/h-dashboard/issues/755) + تصمیمات تأییدشدهٔ کاربر:
> 1. دامنه = **همه‌چیز**: تریاژ + گپ‌ها + شش ایشوی باز، فازبندی‌شده.
> 2. #737/#739 = بستن با کامنت + ایشوی جدا برای هر گپ واقعی.
> 3. خروجی = همین فایل در `plans/`، کامیت و پوش روی برنچ فعلی.
> 4. **چهار تصمیم فاز ۳ حل‌شده** (۲۰۲۶-۰۹-۳۰):
>    - **#743:** قالب PR را اجراکننده می‌سازد؛ مراحل دقیق اعمال branch protection در مرورگر در گزارش/کامنت final به کاربر داده می‌شود تا خودش اعمال کند (توکن `haileen5` فقط `pull` دارد).
>    - **#740:** هشدار = **اعلام داخلی اپ برای نقش `admin`** از طریق `NotificationService::send()` موجود + ویجت داشبورد. بدون زیرساخت جدید (ایمیل/وب‌هوک).
>    - **#742:** **endpoint موقت روی همین اپ** — `POST /csp-report` با ذخیرهٔ لاگی، هدر با `Reporting-Endpoints` (+ `report-to`) روی همان دامنه؛ جابجایی به دامنهٔ جدا در ایشوی بعدی ثبت شود.
>    - **#738:** ستون `phone`، رشته‌ای، `nullable`، **بدون ولیدیشن سخت‌گیرانه**.
>
> Category: Process + Implementation | فازها: 0 تا 4

این پلن **self-contained** است: اجراکننده این مکالمه را ندیده. هر ارجاعی به کد، داخل همین فایل نقل شده و روی کامیت بالا راستی‌آزمایی شده است.

---

## زمینه (Context)

#755 یک گزارش تریاژ از وضعیت ایشوهای باز #734–#744 روی `beta` کانونیکال است. نتیجهٔ بررسی مجدد روی `58e9b34`:

| ایشو | عنوان | وضعیت واقعی روی `58e9b34` (راستی‌آزمایی‌شده) |
|---|---|---|
| #734 | چارت ۳۰ روزه قدیمی‌ترین‌ها را نشان می‌داد | **فیکس‌شده** — PR #748 + #753، تست‌ها موجود (`tests/Feature/DashboardPageTest.php` و `tests/e2e/dashboard/ticket-trend-window.spec.ts`) |
| #735 | ۵۰۰ شدن `/dashboard` بدون واحد | **فیکس‌شده** — PR #746، `tests/Feature/DashboardPageTest.php:72` موجود |
| #736 | بازه `by_day`، روزهای خالی، ایندکس `completed_at` | **فیکس‌شده** — PR #747، `ReportsByDayRangeTest` / `ReportsDailyWindowTest` / `CompletedAtIndexTest` موجود |
| #737 | خروجی Excel پرسنل | **فیکس‌شده با گپ** — `app/Exports/PersonsExport.php` + `routes/web.php:75` + `tests/Feature/PersonsExportTest.php` موجود. **گپ ۱:** `app/Exports/PersonsExport.php:92` → `'unit' => $person->unit->name ?? '-'` (فقط نام، نه مسیر کامل/breadcrumb — بند ۲ اصلاحات مهم ایشو). **گپ ۲:** ستون «تاریخ تولد» در ستون‌های خروجی نیست (`grep birth_date` در `PersonsExport.php` بی‌نتیجه) |
| #738 | شماره تماس پرسنل | **فیکس‌نشده** — هیچ migration تماسی در `database/migrations/*persons*` نیست |
| #739 | `composer verify` و هوک‌ها | **فیکس‌شده؛ بررسی گپ‌ها:** ✅ `composer.json` اسکریپت `verify` (خط ۷۷) + `hooks:install` (خط ۸۲) دارد؛ `scripts/verify.sh`، `scripts/install-hooks.sh`، `.githooks/{pre-commit,pre-push}` موجودند؛ `AGENTS.md:295-327` مستند شده. **انحراف ۱** (pre-commit فقط Pint): با جدول اجماع لایه‌ها منطبق است (`گزارش #755` هم همین را می‌گوید) → تصمیم، نه کد. **انحراف ۲** (فایل فیلترهای غیرپارالل): بند ۶ به‌روزرسانی ۲۹ سپتامبر همین بند را **حذف و جایگزین کرد** (بالا بردن `statement_timeout`) و آن هم انجام شده — `tests/Feature/UnitModelTest.php:183,201,218` → `withStatementTimeout(10000, …)`. **نتیجه: گپ کدی باقی نمانده** |
| #740 | observability برای `SyncZabbixJob` | **فیکس‌نشده** — `app/Jobs/SyncZabbixJob.php:42-44` هنوز فقط `Log::error`؛ نه `report($e)`، نه رکورد دائمی، نه ویجت |
| #741 | adapter زیبکس | **فیکس‌نشده** — `app/Services/Zabbix/` و `app/Zabbix/` وجود ندارند؛ `TrafficController.php:40-43` و `MultiLatestValueController.php:33` هنوز inline `catch (Throwable)` با ۵۰۳ |
| #742 | مسیر `/csp-report` ثبت نشده | **فیکس‌نشده** — `SecurityHeaders.php:20` هنوز `report-uri /csp-report` دارد؛ در `routes/` و `config/` ثبتی نیست |
| #743 | branch protection + قالب PR | **فیکس‌نشده** — `.github/PULL_REQUEST_TEMPLATE.md` نیست؛ `GET /branches/beta/protection` روی کانونیکال → **404** |
| #744 | `scripts/sync-beta.sh` | **فیکس‌نشده** — فایل موجود نیست |

وضعیت CI: آخرین اجرای `Tests` روی `beta` سبز است (`gh run list --repo asgarimehdi/h-dashboard --branch beta`)؛ tip کانونیکال `58e9b34` (merge PR #754) است.

---

## قواعد کلی برای اجرا (مهم)

- **همهٔ کار روی برنچ فعلی این سرور (`sydney`)**؛ هیچ‌وقت مستقیم روی `beta` push نشود. هر فاز/ایشو: commit با پیام معنادار (`fix(...)`, `feat(...)`, `docs(...)`, `chore(github): ...`) + push با refspec صریح `git push origin HEAD:refs/heads/sydney`.
- **درخواست PR فقط با گفتن کاربر** (`pr`) — از همین شاخه به `beta` کانونیکال، از طریق GitHub MCP. merge فقط با دستور صریح کاربر.
- فایل `package-lock.json` محلی یک تغییر از پیش موجود دارد (۳ خط) — **در هیچ کامیتی staged نشود**.
- فایل‌های خارج از scope هر ایشو را دست نزنید. بعد از هر تغییر PHP: `vendor/bin/pint --dirty --format agent` و `vendor/bin/phpstan analyse --no-progress` (مراقب `phpstan-baseline.neon` خطی — بعد از ویرایش فایل baselined: `--generate-baseline` و تأیید `git diff phpstan-baseline.neon` بدون addition).
- تست: `composer test` (config:clear + route:clear + سریال). تست جدید Feature با `use InteractsWithTestSetup;`.
- تصمیم‌های چهارگانهٔ فاز ۳ در همین پلن حل شده‌اند (سربرگ بالای فایل) — به بخش همان ایشو رجوع کنید، دوباره پرسیده نشود. برای هر **تصمیم جدیدِ خارج از این چهار مورد** → **STOP و گزارش**؛ نه حدس، نه پیاده‌سازی خودسرانه.

---

## فاز 0 — baseline (بدون تغییر کد)

1. sync جلسه:
   ```bash
   cd h-dashboard
   git fetch https://github.com/asgarimehdi/h-dashboard beta
   git rev-list --left-right --count HEAD...FETCH_HEAD   # انتظار: 0 0 در حالت sync
   ```
   اگر عقب بود: `git merge --ff-only FETCH_HEAD`، سپس push برنچ: `git push origin HEAD:refs/heads/sydney` (و در صورت نیاز `git push origin FETCH_HEAD:refs/heads/beta` برای همگام‌سازی فورک).
2. پایهٔ سبز: `composer verify` (preflight + view:clear + pint --test + phpstan + سوئیت). اگر قرمز بود و به تغییرات این پلن مربوط نبود → STOP و گزارش.

**Done:** `HEAD == 58e9b34` یا جدیدتر + `composer verify` سبز (یا گزارش صادقانهٔ خرابی baseline).

---

## فاز 1 — تریاژ گیت‌هاب (بدون تغییر کد)

### 1a. بستن #734، #735، #736

برای هر سه، کامنت فارسی شامل PR فیکس + نام فایل‌های تست (از جدول بالا) و سپس `gh issue close`:

```bash
gh issue comment 734 --repo asgarimehdi/h-dashboard --body "فیکس‌شده روی beta (PR‌های #748 و #753). رگرسیون: tests/Feature/DashboardPageTest.php + tests/e2e/dashboard/ticket-trend-window.spec.ts. بسته می‌شود."
gh issue close 734 --repo asgarimehdi/h-dashboard --reason completed
# معادل برای #735 (PR #746، DashboardPageTest.php:72) و #736 (PR #747، ReportsByDayRangeTest/ReportsDailyWindowTest/CompletedAtIndexTest + مایگریشن completed_at)
```

**Done:** هر سه `state: closed` با `reason completed`.

### 1b. ارزیابی گپ #739 و بستن آن

بررسی مجدد اجرا روی tip فعلی (اگر چیزی از جدول Context تغییر کرده بود، اینجا تازه شود):

```bash
grep -n '"verify"' composer.json && ls scripts/verify.sh .githooks/pre-commit .githooks/pre-push
grep -n "withStatementTimeout" tests/Feature/UnitModelTest.php
grep -n "composer verify" AGENTS.md
```

- **انتظار (راستی‌آزمایی‌شده روی `58e9b34`): گپ کدی وجود ندارد.** انحراف ۱ با جدول اجماع لایه‌ها منطبق است؛ انحراف ۲ توسط بند ۶ به‌روزرسانی ۲۹ سپتامبر ایشو حذف شده و جایگزینش (timeout ۱۰ ثانیه) اعمال است.
- در این حالت: **ایشوی جدید ساخته نشود** (ایشوی خالی ساختن معنا ندارد). کامنت جمع‌بندی زیر + بستن:

```
فیکس‌شده روی beta (PR‌های #745 و #754) و مستند در AGENTS.md:295-327.
ارزیابی گپ‌ها:
- انحراف ۱ (pre-commit فقط Pint): منطبق با جدول اجماع لایه‌ها (pre-commit=Pint، pre-push=PHPStan کامل، CI=سوئیت کامل) — تصمیم، نه کد.
- انحراف ۲ (لیست فیلترهای غیرپارالل): توسط بند ۶ به‌روزرسانی ۲۹ سپتامبر ایشو حذف شد؛ جایگزین (statement_timeout ۱۰ ثانیه در UnitModelTest:183,201,218) اعمال است.
گپ کدی باقی نمانده — بسته می‌شود.
```

- اگر اجراکننده گپ **کدی** تازه‌ای پیدا کرد → آن‌وقت ایشوی جدا بسازد و #739 را با لینک آن ببندد.

**Done:** #739 بسته شده؛ تصمیم‌ها در کامنت ثبت‌اند.

### 1c. ایشوی گپ #737 و بستن #737

ایشوی جدید بساز (کاربر قبلاً تأیید کرده):

```bash
gh issue create --repo asgarimehdi/h-dashboard --title "گپ‌های خروجی Excel پرسنل (#737): مسیر کامل واحد + ستون تاریخ تولد" --body "$(cat <<'EOF'
گپ‌های باقی‌ماندهٔ #737 (راستی‌آزمایی‌شده روی 58e9b34):

1. **واحد سازمانی فقط نام است، نه مسیر کامل.** `app/Exports/PersonsExport.php:92`:
   `'unit' => $person->unit->name ?? '-'`
   طبق بند ۲ اصلاحات مهم #737 باید breadcrumb کامل باشد (مثل ستون «مسیر کامل» خروجی واحدها، از `UnitTreeService`) چون نام‌هایی مثل «پایگاه» تکرار می‌شوند.
2. **ستون «تاریخ تولد» اضافه نشده.** جدول `persons` ستون `birth_date` دارد و در ستون‌های نهایی #737 آمده، ولی در خروجی نیست.

Acceptance:
- [ ] خروجی، مسیر کامل واحد را با `UnitTreeService` می‌سارد (تست: دو واحد هم‌نام در شاخه‌های مختلف)
- [ ] ستون «تاریخ تولد» با همان قالب شمسیِ بقیهٔ تاریخ‌ها
- [ ] تست‌های `tests/Feature/PersonsExportTest.php` به‌روز + سبز
- [ ] `pint` و `phpstan` سبز
EOF
)"
```

سپس کامنت روی #737 + بستن (`gh issue close 737 --reason completed`) با ارجاع به ایشوی جدید و لیست بقیهٔ موارد انجام‌شده (مسیر export، دامنهٔ خالی، جست‌وجوی چند-termی، ستون وضعیت).

**Done:** ایشوی گپ = **#756** (ساخته شد، در فاز ۲ فیکس و با PR #757 بسته شد)؛ #737 بسته شده.

---

## فاز 2 — فیکس گپ #737 (کد)

ایشوی جدیدِ فاز ۱c را اجرا کن. فقط دو فایل در scope:

1. **مسیر کامل واحد:** در `app/Exports/PersonsExport.php` ستون `unit` از `unit->name` به مسیر کامل تغییر می‌کند. الگو: `UnitTreeService::ancestorChain()` (نگاه کن به `app/Services/UnitTreeService.php` و نحوهٔ استفادهٔ `app/Exports/UnitsExport.php` از ستون «مسیر کامل» — همان الگو را عیناً تکرار کن؛ `UnitsExport` مرجع رسمی همین قرارداد است).
2. **ستون تاریخ تولد:** افزودن `birth_date` به آرایهٔ ستون‌ها با همان قالب شمسیِ `hire_date` (همان formatterی که فایل الان برای تاریخ استخدام استفاده می‌کند — کپی از همان فراخوانی، نه formatter جدید).

تست‌ها (در `tests/Feature/PersonsExportTest.php` موجود، گسترش بده):
- RED اول: دو unit هم‌نام در شاخه‌های جدا → خروجی باید مسیر کامل متفاوت داشته باشد؛ و `birth_date` مقداردار → سلول ستون «تاریخ تولد» خالی نیست.

 Gates: `vendor/bin/pint --dirty --format agent` → `vendor/bin/phpstan analyse --no-progress` → `composer test tests/Feature/PersonsExportTest.php` و در پایان `composer test`.

commit: `fix(export): persons export — full unit breadcrumb + birth date column (#755)` → push.

**Done:** تست‌های جدید سبز + `composer test` کامل سبز + push شده. سپس کامنت «فیکس شد» + بستن ایشوی گپ.

---

## فاز 3 — شش ایشوی باز

ترتیب پیشنهادی (ارزان → گران؛ ایشوهای دارای تصمیمِ مسدودکننده آخر):

### 3.1 #744 — `scripts/sync-beta.sh` (S)

مشخصات کامل در خود ایشو. خلاصهٔ قابل‌اجرا:
- فایل جدید `scripts/sync-beta.sh` (bash، `set -euo pipefail`):
  1. گزارش: `git rev-list --left-right --count HEAD...origin/beta` + `git merge-base --is-ancestor origin/beta HEAD` → خروجی خوانا `behind X, ahead Y`.
  2. مقایسه با **رفرنس ریموت صریح** `origin/beta`، نه upstream کانفیگ‌شده.
  3. حالت `--ff-only`؛ اگر ff ممکن نبود → پیام واضح + `exit 1`، **هرگز merge خودکار**.
  4. ۵ خط مستند بالای فایل (چه می‌کند / چه نمی‌کند).
- ارجاع در `AGENTS.md` (یک خط در بخش مرتبط).
- Acceptance: `bash -n scripts/sync-beta.sh` (و `shellcheck` اگر هست)؛ روی برنچ واقعی عقب‌مانده عدد درست گزارش می‌شود؛ روی برنچ واگرا merge نمی‌کند.
- **تست خودکار ندارد** — راستی‌آزمایی دستی با یک برنچ آزمایشی محلی (مثلاً `git branch test-behind origin/beta~3`) و گزارش خروجی در PR.

### 3.2 #743 — گاردریل مرج (S) — تصمیم: اجراکننده قالب می‌سازد، protection با کاربر

- **Step 1:** `.github/PULL_REQUEST_TEMPLATE.md` جدید با چک‌لیست خود ایشو (sync با beta / تست محلی با `composer verify` / commit+push / توضیح «چرا» نه فقط «چه»).
- **Step 2:** نوشتن **مراحل دقیق اعمال branch protection در مرورگر** و گذاشتن آن در کامنت #743 (یا گزارش final به کاربر). مراحل استاندارد:
  1. در `asgarimehdi/h-dashboard` → تب **Settings → Branches → Add branch protection rule**.
  2. Branch name pattern: `beta`.
  3. فعال کردن **Require a pull request before merging** (حداقل ۱ approve اگر تیم >۱ نفر است، وگرنه ۰).
  4. فعال کردن **Require status checks to pass before merging** و اضافه کردن چک‌های jobهای `.github/workflows/test.yml` (نام دقیق jobها را از همان فایل بخوان و عیناً بنویس).
  5. Save.
- **هرگز** سعی نکن از API با توکن فعلی اعمال شود — `haileen5` فقط `pull` دارد و پاسخ 404/403 می‌دهد.
- Acceptance: قالب در PR جدید ظاهر می‌شود؛ مراحل protection نوشته و تحویل داده شده است. (اعمال واقعی protection = کارِ کاربر؛ در گزارش final یادآوری شود.)

### 3.3 #738 — شماره تماس پرسنل (S-M) — تصمیم: ستون `phone`

- **تصمیم قطعی:** ستون `phone`، رشته‌ای (`string`)، `nullable`، **بدون ولیدیشن سخت‌گیرانه** — در فرم یک `x-input` با ولیدیشن سادهٔ حداکثر طول (مثلاً `max:20`)، بدون اجباری بودن.
- Scope عیناً از ایشو: migration (`YYYY_MM_DD_000001_add_phone_to_persons_table.php`، rollback تمیز)، فیلد فرم در `resources/views/livewire/kargozini/person.blade.php`، ستون در `PersonImport`، نمایش در جزئیات رکورد (نمایش در فهرست اختیاری، طبق ایشو).
- `@property` روی مدل `Person` را به‌روز کن (`@property string|null $phone` — PHPStan level 6).
- تست Feature برای هر سه مسیر (ذخیرهٔ فرم، ایمپورت، نمایش) با `InteractsWithTestSetup`.
- Gates: pint / phpstan / `composer test`.
- بعد از بسته شدن: «شماره تماس» را می‌توان به ستون‌های اکسل پرسنل اضافه کرد (یادداشت در کامنت).

### 3.4 #741 — adapter زیبکس (M)

- **قرارداد ثابت:** `TrafficController.php:40-43` و `MultiLatestValueController.php:33` الان `Throwable` را می‌گیرند و **503** با پاسخ `['error' => 'Service temporarily unavailable']` برمی‌گردانند — بعد از استخراج adapter هم باید دقیقاً همین بماند. تست‌های موجود نباید تغییر کنند.
- Steps (از ایشو): اینترفیس adapter در `app/Services/Zabbix/` → بازنویسی دو کنترلر → unit test با stub (قطعی/تایم‌اوت/پاسخ نامعتبر) بدون سرور واقعی.
- خارج از scope: `SyncZabbixJob` (#740 جدا است)، صفحهٔ مدیریت زیبکس #700.
- Gates: تست‌های موجود کنترلرها **بدون تغییر** سبز + تست واحد جدید + pint/phpstan.

### 3.5 #740 — observability برای `SyncZabbixJob` (S-M) — تصمیم: اعلام داخلی ادمین

- **Step 1:** `report($e)` در `failed()` (`app/Jobs/SyncZabbixJob.php:42-44`، علاوه بر `Log::error` فعلی) + ثبت رکورد هر اجرا (موفق/ناموفق/زمان) — تصمیم کدگذار: جدول وضعیت sync جدید (migration با شماره‌گذاری `YYYY_MM_DD_000001_...`) یا استفاده از `notifications`؛ هر کدام ساده‌تر بود.
- **Step 2:** ویجت «آخرین sync موفق» در داشبورد + تمایز «کش قدیمی» از «sync سالم».
- **Step 3 (هشدار N fail متوالی): N = ۳** (طبق Acceptance خود ایشو). کانال = **اعلام داخلی اپ برای نقش `admin`** از طریق `NotificationService::send()` موجود (`NotificationService::send()` استاتیک، نه `create()`؛ `app/Notifications/` وجود ندارد و ساخته نمی‌شود). پس از سومین fail متوالی، یک اعلان برای کاربران نقش admin؛ شمارش fail متوالی ریست پس از یک اجرا موفق.
- Acceptance: fail تکی در داشبورد دیده شود؛ سه fail متوالی → اعلان داخلی admin؛ تست (fail متوالی → رکورد + اعلان) + pint/phpstan سبز.

### 3.6 #742 — CSP reporting (S-M) — تصمیم: endpoint موقت روی همین اپ

- **تصمیم قطعی:**
  1. مسیر `POST /csp-report` روی همین اپ ثبت شود (روت `web` یا `api` بسته به اینکه لاگین لازم نداشته باشد — CSP report باید **بدون auth** بتواند بیاید؛ throttle سبک `throttle:60,1` بگذار).
  2. گزارش‌ها فقط **لاگ** شوند (`Log::warning('csp-report', ...)` با بدنهٔ نرمال‌شده؛ ذخیرهٔ دائمی/جدول لازم نیست — دادهٔ دورهٔ اعتبارسنجی است).
  3. در `SecurityHeaders.php:20`: `report-uri` **حذف** (deprecated و در Chrome بی‌اثر) و جایگزینی با هدر `Reporting-Endpoints: csp-endpoint="/csp-report"` + `; report-to csp-endpoint` در CSP.
  4. یک **یادداشت موقتی بودن** در کامنت #742 و داخل پلن: مقصد نهایی طبق خود ایشو باید endpoint دامنهٔ جدا باشد؛ جابجایی بعد از مشخص شدن زیرساخت، در ایشوی جدا ثبت شود (این تصمیم را در کامنت #742 هم بنویس).
- **خارج از scope:** enforce کردن CSP (دورهٔ report-only ادامه دارد)، سرویس دامنهٔ جدا.
- تست: POST خام نمونهٔ گزارش به `/csp-report` → 2xx؛ هدر پاسخِ یک صفحه حاوی `Reporting-Endpoints` (تست موجود SecurityHeaders را به‌روز کن اگر هدر را assert می‌کند).
- Gates: pint / phpstan / `composer test`.

---

## فاز 4 — جمع‌بندی #755

وقتی همهٔ فازهای بالا انجام شد:

```bash
gh issue comment 755 --repo asgarimehdi/h-dashboard --body "<جمع‌بندی: بسته‌شدن #734/#735/#736/#737/#739، شمارهٔ ایشوی گپ #737، وضعیت هر ۶ ایشوی فاز ۳>"
```

ایشوی #755 را **فقط با تأیید کاربر** ببند (اگر همهٔ فازها انجام شده باشند، `--reason completed`).

---

## ترتیب و وابستگی‌ها

```
فاز 0 (sync + baseline)
   └─ فاز 1 (تریاژ: 1a → 1b → 1c)
        └─ فاز 2 (فیکس گپ #737 — به ایشوی 1c وابسته)
   └─ فاز 3 (مستقل از فاز ۲؛ ترتیب 3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6)
        └─ فاز 4 (جمع‌بندی — فقط بعد از اتمام ۰ تا ۳)
```

هر آیتمِ فاز ۳ مستقل است؛ چهار تصمیم قبلی حل شده و هیچ آیتمی دیگر مسدود نیست. تنها کارِ باقی‌ماندهٔ خارج از دسترسِ اجراکننده: **اعمال branch protection در مرورگر توسط کاربر** (۳.2) — آن را در گزارش final یادآوری کن.

## Test plan (جمعی)

- بعد از هر فاز کدی: `composer test` کامل سبز؛ فاز ۲ حداقل `PersonsExportTest` سبز.
- فاز ۳.۱: `bash -n` + راستی‌آزمایی دستی روی برنچ آزمایشی.
- فاز ۳.۳ و ۳.۴ و ۳.۵ و ۳.۶: تست Feature/Unit جدید طبق Acceptance همان ایشو.
- E2E لازم نیست مگر رفتار UI داشبورد (ویجت sync) تغییر کند — در آن صورت `bash scripts/e2e-test.sh` (با دقت: اسکریپت trap ندارد؛ بعدش `grep DB_DATABASE .env` باید `h_dashboard` باشد).

## Escape hatches (توقف و گزارش، نه بداهه‌کاری)

- `composer verify` قرمز شد و به تغییرات مرتبط نیست → STOP.
- به تصمیمی **خارج از چهار تصمیم حل‌شدهٔ سربرگ** برخوردی → STOP و گزارش.
- اگر `gh` یا GitHub MCP روی ایشوها permission نداشت → کامنت/بستن را رها کن، گزارش بده.

## Maintenance note

این پلن یک‌بارمصرف است: بعد از اتمام فاز ۴، وضعیت به‌روز ایشوها در خود گیت‌هاب مرجع است و این فایل می‌تواند بماند به‌عنوان تاریخچه (مثل `026-disease-map-system-spec.md`). ایشوی جدیدِ گپ #737 در بند ۱c بعد از ساختن، شماره‌اش را اینجا ثبت کن.
