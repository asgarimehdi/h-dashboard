# Plan 024: E2E Data Independence (خودکفایی تست‌های E2E)

> **Source**: PR #625 by nashenas7 — `plans/e2e/016-e2e-data-independence.md`
> **Adopted into**: plans/ root for unified execution tracking.

---
## ⚠️ TL;DR فارسی

**مشکل:** E2E به داده واقعی وابسته: اعداد مطلق، رکوردهای واقعی، اکانت مشترک.

**راه‌حل:** fixtures یکتا + assert نسبی + teardown.

**ریسک:** 🟡 متوسط

---

| Field      | Value                              |
|------------|------------------------------------|
| Category   | tests (E2E)                        |
| Effort     | L                                  |
| Risk       | MED                                |
| Priority   | P0                                 |
| Depends on | none (infrastructure first)        |
| Base SHA   | 5f9c24e                            |
| Branch     | celin                              |

---

## 1. Problem

تست‌های E2E فعلی رفتار اپ را تست نمی‌کنند؛ اسنپ‌شات دیتای امروزند. سه وابستگی
شکننده به دیتای واقعی دارند:

1. **عدد مabsolute** — `317` یوزر، `318` پرسنل، `831` واحد، `449` سخت‌افزار،
   `290/241/49` در گزارش‌ها. هر insert/delete ساده می‌شکندشان.
2. **رکورد واقعی** — جستجوی `هادیلو`، `عسگری`، کدهای ملی واقعی. حذف یا تغییر
   آن شخص = شکست تست.
3. **اکانت مشترک** — همه تست‌ها با ۴ اکانت سیدشده لاگین می‌کنند؛
   `password-change.spec.ts` رمز اکانت مشترک را عوض می‌کند و برمی‌گرداند
   (اگر وسطش بمیرد، رمز خراب می‌ماند).

نتیجه: با هر تغییر دیتا، تست‌ها می‌شکنند و به‌مرور نادیده گرفته می‌شوند.
هدف این پلن: هر ران دیتای خودش را بسازد، آخرش پاک کند، و assertها نسبی باشند.

## 2. Current State

```
tests/e2e/
├── shared/fixtures.ts          # hardcoded fallback 12345678, 4 seeded accounts
├── auth/password-change.spec.ts # mutates shared account password
├── tickets/new.spec.ts         # searches "زنجان" (real unit), hardcoded CWD
├── users/crud.spec.ts          # searches "هادیلو" (real person)
├── personnel/list.spec.ts      # asserts count=318, searches "4411015056"
├── organization/units.spec.ts  # asserts count=831, searches "دانشگاه علوم پزشکی زنجان"
├── hardware/list-filters.spec.ts # asserts count=449
├── reports/map-no-boundary.spec.ts # asserts 290/241/49
└── dashboard/stats.spec.ts     # hardcoded count assertions
```

Key pattern (duplicated in ~20 files):
```typescript
// Before: hardcoded absolute counts
await expect(page.locator('.mary-table-pagination')).toContainText('از 449');

// After: relative assertion
const count = await page.locator('.mary-table-pagination').innerText();
const match = count.match(/(\d+)/);
expect(parseInt(match[1])).toBeGreaterThan(0);
```

## 3. Principles

- هر ران دیتای خودش را با **پیشوند یکتا** (`E2E-<runId>-...`) بسازد، آخرش پاک کند.
- assertها **نسبی** باشند (قبل/بعد، وجود رکورد ساخته‌شده) نه عدد ثابت.
- هیچ کر دن شل واقعی در فایل ترک‌شده نباشد؛ فقط از `.env.test` (ایگنورشده).
- تست‌های Pest دست نخورند؛ رمز سیدرها (`12345678`) هم عوض نشود.

## 4. Architecture

```
playwright.config.ts
  ├── globalSetup → ساخت یوزرها + دیتای حداقلی (runId یکتا)
  ├── تست‌ها → فقط با دیتای runId خودشان کار می‌کنند
  └── globalTeardown → حذف همه رکوردهای E2E-<runId>
```

- کامند آرتیزان جدید `e2e:fixtures {runId} {action=up|down}` که منطق
  ساخت/حذف در PHP بماند (مدل‌ها، ولیدیشن، نقش‌ها = همان منطق واقعی).
  صدا زدن از `globalSetup` با `execSync`.
- رمز یوزرهای موقت از `TEST_PASSWORD` در `.env.test` بیاید، نه هاردکد.
- `fixtures.ts` فقط لاگین با همین یوزرهای موقت.

## 5. Steps

### Phase 1 — زیرساخت (بدون تغییر تست‌ها)

**Step 1**: `app/Console/Commands/E2eFixturesCommand.php`
- با `runId` چهار یوزر (admin/unit_manager/expert/user) + یک واحد تستی + چند پرسنل تستی با
  نام‌های `E2E-<runId>-...` می‌سازد
- با `down` همه را پاک می‌کند
- رمز از `TEST_PASSWORD` env می‌خواند

**Step 2**: `tests/e2e/global-setup.ts` و `global-teardown.ts`
- تولید `runId`، صدا زدن کامند، ذخیره `runId` در فایل موقت

**Step 3**: `playwright.config.ts`
- وصل کردن setup/teardown + fail-fast اگر `TEST_PASSWORD` ست نباشد

**Verify**:
```bash
npx playwright test  # باید مثل قبل سبز شود (هنوز assertها قدیمی‌اند)
```

### Phase 2 — نسبی کردن assertها (فایل به فایل)

| فایل | مشکل | راه‌حل |
|---|---|---|
| `users/list.spec.ts` | `317`، `32`، `هادیلو`، `0023548258` | شمارش قبل/بعد؛ جستجو روی پرسنل `E2E-<runId>` |
| `personnel/list.spec.ts` | `318`، `4411015056`، select index | `318` → `>0` + رفتار فیلتر؛ select با value نه index |
| `organization/units.spec.ts` | `831`، `دانشگاه علوم پزشکی زنجان` | جستجو روی واحد `E2E-<runId>` |
| `hardware/list-filters.spec.ts` | `449` | فیلتر روی سخت‌افزار ساخته‌شده با سریال یکتا |
| `reports/map-no-boundary.spec.ts` | `290/241/49` | ساخت واحد بدون مرز → عدد یکی زیاد شود |
| `dashboard/stats.spec.ts` | کامنت اعداد ثابت | فقط presence و سازگاری (جمع اجزا = کل) |
| `tickets/new.spec.ts` | تیکت `تست خودکار E2E` بدون پاکسازی | subject یکتا با runId + حذف در teardown |
| `auth/password-change.spec.ts` | جهش روی اکانت مشترک | اجرا روی یوزر موقت اختصاصی همین اسپک |
| `rbac/roles.spec.ts` | وابسته به ۴ اکانت سیدشده | لاگین با ۴ یوزر ساخته‌شده در setup |
| `users/crud.spec.ts` | `هادیلو` | ساخت/ویرایش/حذف یوزر `E2E-<runId>` |

### Phase 3 — اثبات پایداری

1. `grep -rEn "toContainText\(['\"][0-9]{2,}" tests/e2e/` → صفر نتیجه
2. `npx playwright test` → سبز
3. تست ضربه: یک یوزر/پرسنل دستی اضافه و کم کن، دوباره اجرا → همچنان سبز
4. `git status` → هیچ فایل حاوی کر دن شل واقعی ترک نشده

## 6. STOP Conditions

- فایلی از `tests/e2e/` توسط PR دیگری تغییر کرده → drift check اجرا کن
- `E2eFixturesCommand` کرش کند → لیست خطا را چاپ کن، متوقف شو
- teardown رکوردها را پاک نکند → فوراً متوقف شو (دیتای آلوده)

## 7. Test Plan

```bash
# Phase 1 verification
php artisan e2e:fixtures test-run-001 up
php artisan e2e:fixtures test-run-001 down

# Phase 2 verification
npx playwright test tests/e2e/users/list.spec.ts
npx playwright test tests/e2e/personnel/list.spec.ts
npx playwright test tests/e2e/organization/units.spec.ts

# Full suite
npx playwright test

# No hardcoded counts
grep -rEn "toContainText\(['\"][0-9]{2,}" tests/e2e/ | wc -l  # expect 0
```

## 8. Done Criteria

- [ ] `E2eFixturesCommand` works with `up` and `down`
- [ ] `globalSetup` / `globalTeardown` wired in `playwright.config.ts`
- [ ] Zero hardcoded absolute counts in `tests/e2e/`
- [ ] All E2E tests pass with `npx playwright test`
- [ ] Teardown cleans all E2E-created records

## 9. Risk Mitigation

- **دیتابیس جدا vs مشترک**: ساده‌ترین حالت همین DB فعلی + پیشوند یکتا و teardown.
- **پارالل**: چون `fullyParallel` است، یوزرها باید per-run باشند نه per-test.
- **حجم کار**: ~۳۲ فایل، ولی تغییرات مکانیکی و تکراری‌اند.

## 10. Already Done (in PR #625)

- `tests/e2e/shared/fixtures.ts`: fallback هاردکد `12345678` حذف شد
- `tests/e2e/users/crud.spec.ts`: `fill('12345678')` → `TEST_USER.password`
- `.env.test.example` (بدون مقدار واقعی) اضافه شد
