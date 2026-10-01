# پلن ۰۲۸ — چرخهٔ عمر نقشه: انتقال مالکیت Leaflet از `window` به Alpine store

> Written against commit: `d6b42e1` (beta کانونیکال = origin/beta = برنچ `celin` این سرور)
> Date: 2026-10-01
> Category: Implementation + Refactor | فازها: 0 تا 5 | فایل‌های درگیر: 8
>
> **این پلن self-contained است.** اجراکننده این مکالمه را ندیده. هر ارجاعی به کد، خطای
> مشاهده‌شده و شواهد مرورگر، داخل همین فایل نقل شده و روی کامیت بالا راستی‌آزمایی شده است.
>
> **تصمیم کاربر (تأییدشده):** Alpine store مشترک با چرخهٔ عمر صریح — به‌جای شرط هویت
> کانتینر، و به‌جای استقلال کامل هر صفحه. ظاهر و رفتار صفحه‌ها ۱:۱ حفظ می‌شود.

---

## باگ (نقل دقیق از گزارش کاربر)

> «وقتی مستقیم وارد این لینک میشوم نقاط لود می شود ولی وقتی از منوی دیگری وارد میشوم لود نمیشود
> `http://celin:8000/maps/point`»

URL: `/maps/point`

### بازتولید قطعی (اندازه‌گیری‌شده، نه تخمینی)

با مرورگر واقعی، ۶ بار تکرار: **۰ از ۶ موفق**، یعنی ۱۰۰٪ خرابی.

| مسیر ورود | نتیجه |
|---|---|
| لود مستقیم `page.goto('/maps/point')` | **۷۸۳ نشانگر** — سالم |
| ناوبری منو از `/dashboard` | ۷۸۳ — سالم (نقشهٔ قبلی وجود ندارد، رقابتی نیست) |
| ناوبری منو از `/maps/county` | **۰ نشانگر** — خراب |
| ناوبری منو از `/maps/unit` | **۰ نشانگر** — خراب |
| ناوبری منو از `/maps/route2` | **۰ نشانگر** — خراب |

علت تفاوت: لود مستقیم و ناوبری از `/dashboard` هیچ نمونهٔ Leaflet قبلی‌ای در `window` نمی‌گذارند،
پس رقابتی رخ نمی‌دهد. ناوبری نقشه→نقشه نقشهٔ مرده را به ارث می‌برد.

### شواهد ترتیب اجرا (کنسول واقعی، ثبت‌شده)

```
waitForMap REGISTERED          ← صفحهٔ میزبان (maps.point)
waitForMap RESOLVED            ← به نقشهٔ county وصل شد (که هنوز مرده است)
point callback ENTER           ← markersLayer را روی نقشهٔ مرده ساخت
initMap ENTER container-found  ← نقشهٔ تازه ساخت و لایه‌ها را پاک کرد
```

تأیید قطعی هویت کانتینر، همان لحظه:

```
window.map.getContainer() === document.getElementById('map')  →  True   (بعداً)
old container still connected to DOM?                          →  False (همان لحظه)
old container === new #map?                                   →  False
old map === window.map?                                       →  False
```

یعنی `maps.point` به نقشه‌ای وصل شد که بلافاصله بعد نابود می‌شد.

---

## ریشهٔ باگ (سه لایهٔ که با هم جمع شده‌اند)

### ۱. مالکیت سراسری بدون چرخهٔ عمر

`resources/views/livewire/maps/map.blade.php:62` نمونهٔ Leaflet را روی `window.map` می‌گذارد.
`window` هرگز پاک نمی‌شود، پس نقشهٔ صفحهٔ قبلی از ناوبری SPA زنده می‌ماند.

### ۲. `@script` فقط یک‌بار در هر نمونهٔ کامپوننت اجرا می‌شود

از سورس نصب‌شدهٔ Livewire 4.4.7 تأیید شد — `vendor/livewire/livewire/dist/livewire.esm.js:16134`:

```js
function evaluateScripts(component, effects) {
  let scripts = effects.scripts;
  if (scripts) {
    Object.entries(scripts).forEach(([key, content]) => {
      onlyIfScriptHasntBeenRunAlreadyForThisComponent(component, key, () => { … });
    });
  }
}
```

پس «کدام اسکریپت زودتر اجرا می‌شود» اصلاً تضمینی نیست، و رندر مجدد هم اجرا را تکرار نمی‌کند.

### ۳. قرارداد مبهم بین دو کامپوننت

`maps.map` می‌گوید «من `window.map` را می‌سازم»؛ صفحهٔ میزبان می‌گوید «منتظر می‌مانم تا بیاید».
هیچ‌کدام اعلام نمی‌کند کدام نمونه معتبر است. شرط انتظار در `maps/point.blade.php:167` فقط
**وجود** `window.map` را می‌سنجد:

```js
if (window.map && typeof window.map.getSize === 'function') { callback(); }
```

نقشهٔ مردهٔ صفحهٔ قبلی این شرط را کامل برآورده می‌کند.

---

## راه‌حل: مالکیت صریح، نه حدس زدن با شرط

### پایهٔ طرح: چرخهٔ عمر Alpine روی ناوبری SPA کار می‌کند

با یک spike اندازه‌گیری شد (و بعد برگردانده شد). روی `#map` موقتاً
`x-data="{ init(){…}, destroy(){…} }"` گذاشته شد و ناوبری SPA اجرا شد:

```
[SPIKE] DESTROY fired   ← قبل از INIT
[SPIKE] INIT fired
```

پس `destroy()` می‌تواند نقشه را نابود کند و `init()` نقشهٔ تازه بسازد. نیازی به حدس زدن نیست.

### پایهٔ طرح: ثبت Alpine store از اسکریپت `<head>` کار می‌کند

Spike دوم: در `resources/views/components/layouts/app.blade.php` یک اسکریپت که گوشه‌دهی به
`alpine:init` می‌زند و `Alpine.store(...)` صدا می‌کند. نتیجه:

```
store registered (alpine:init fired)?  True
Alpine.store('spikeMap'):               {"marker":"from-alpine-init","n":42}
```

**نکتهٔ ترتیب:** پروژه `@vite` در `<head>` دارد ولی **هیچ `@livewireScripts` صریحی ندارد**
(grep در `resources/views/` هیچ موردی برنگرداند). Alpine از طریق تزریق خودکار Livewire
می‌آید و بعد از اسکریپت‌های `<head>` اجرا می‌شود. پس ثبت store در `alpine:init` **حتماً قبل** از
اولین `x-data` اجرا می‌شود. اگر فردا کسی `@livewireScripts` را به `<head>` اضافه کرد، همین
الگو همچنان درست است چون به رویداد گوش می‌دهد نه به ترتیب بارگذاری فایل.

### طرح: یک میان‌افزار، دو قلاب

```js
// resources/js/map-store.js — ثبت در resources/js/bootstrap.js
Alpine.store('map', {
  instance: null,    // نمونهٔ زندهٔ Leaflet
  container: null,   // کانتینری که به آن وصل است

  use(el)    { /* ساخت یا بازاستفاده، با بررسی هویت کانتینر */ },
  release(el){ /* نابودی قطعی: instance.remove() + پاک‌کردن لایه‌ها */ },
})
```

- `maps/map.blade.php` تنها جایی است که `L.map()` صدا می‌زند. `x-data` با متد `init()` → `use()`
  و متد `destroy()` → `release()`.
- صفحه‌های میزبان به‌جای `waitForMap` از `Alpine.store('map').use(el)` صدا می‌زنند و لایه‌هایشان را
  به **همان** نمونه‌ای می‌چسبانند که `use()` برگرداند.

### چرا این ریشه را حل می‌کند

`release()` روی `destroy()` نقشهٔ مرده را نابود می‌کند، پس `instance` دیگر هرگز به کانتینر
جدا‌شده اشاره نمی‌کند. رقابت **از بین می‌رود** — نه با شرط هویت، بلکه با اینکه اصلاً نمونهٔ
آلوده‌ای برای رقابت باقی نمی‌ماند. و چون ترتیب اجرای `@script` تضمین نشده، دیگر به آن تکیه
نمی‌کنیم: هر صفحه خودش مالکیت را می‌گیرد.

---

## محدوده (Scope)

### در محدوده — ۸ فایل

| فایل | نقش در این پلن |
|---|---|
| `resources/js/map-store.js` | **جدید** — میان‌افزار Alpine store |
| `resources/js/bootstrap.js` | وارد کردن میان‌افزار |
| `resources/views/components/layouts/app.blade.php` | نگه‌داشتن اسکریپت‌های Leaflet + پل به store (بدون تغییر ظاهر) |
| `resources/views/livewire/maps/map.blade.php` | تنها مالک `L.map()`؛ `use()`/`release()` |
| `resources/views/livewire/maps/point.blade.php` | مصرف‌کننده — `waitForMap` حذف |
| `resources/views/livewire/maps/county.blade.php` | مصرف‌کننده |
| `resources/views/livewire/maps/unit.blade.php` | مصرف‌کننده |
| `resources/views/livewire/maps/route.blade.php` | مصرف‌کننده (ریسک بالاتر — `window.routingControl`) |
| `resources/views/livewire/maps/route2.blade.php` | مصرف‌کننده (ریسک بالاتر — `window.routingControl`) |
| `resources/views/livewire/reports/map-no-boundary.blade.php` | مصرف‌کننده |
| `tests/e2e/maps/spa-navigation.spec.ts` | **جدید** — تست رگرسیون ناوبری SPA |
| `AGENTS.md` | ثبت الگو + gotcha |

### خارج از محدوده — دست‌نخورده، با دلیل

| فایل | دلیل |
|---|---|
| `resources/views/livewire/map/map-dashboard.blade.php` (`/map`) | نمونهٔ خودش را دارد (`x-data` با `this.map`)، اصلاً `window.map` نمی‌ریسد |
| `resources/views/livewire/units/map.blade.php` (`/units/{id}/map`) | نمونهٔ خودش روی `unitMap`؛ E2E به `window._drawnItems` و `window._mapGeojson` وابسته است — دست‌زدن ریسک بی‌دلیل |
| `resources/views/livewire/maps/interactive.blade.php` (`/maps/interactive`) | `L.map('unitsMap')` خودش را دارد، کاملاً مستقل |
| `public/js/leaflet/*` | کتابخانه‌های vendor |

**قاعدهٔ سخت:** اگر حین اجرا فهمیدی `map-dashboard` یا `units/map` هم به این مشکل می‌خورند،
**متوقف شو و گزارش بده** — آن‌ها خارج از محدوده‌اند و تصمیم جداگانه می‌خواهند.

---

## قواعد کلی برای اجرا

- **همهٔ کار روی برنچ فعلی این سرور (`celin`).** هرگز مستقیم روی `beta` push نشود. هر فاز:
  commit با پیام معنادار + push با refspec صریح `git push origin HEAD:refs/heads/celin`.
- **درخواست PR فقط با گفتن کاربر** (`pr`). merge فقط با دستور صریح.
- فایل `package-lock.json` یک تغییر محلی از پیش دارد — **در هیچ کامیتی staged نشود**
  (نوسان نسخهٔ npm: `@oxc-project/types` 0.151→0.152، `@rolldown/binding-*` 1.2.11→1.2.12).
- بعد از هر تغییر PHP: `vendor/bin/pint --dirty --format agent`.
- **`phpstan-baseline.neon` خطی است.** بعد از ویرایش فایل‌های baselined:
  `composer phpstan-baseline` سپس `git diff phpstan-baseline.neon` باید **۰ addition** نشان دهد.
  entry های موجود: `maps/point` = ۸، `maps/unit` = ۱۱، `reports/map-no-boundary` = ۶، `maps/county` = ۲،
  `maps/map` = ۰، `maps/route` = ۰، `maps/route2` = ۰.
- تست: `composer test` (config:clear + route:clear + سریال). تست جدید Feature با
  `use InteractsWithTestSetup;`.
- برای هر **تصمیم جدیدی خارج از این پلن** → **STOP و گزارش**؛ نه حدس، نه پیاده‌سازی خودسرانه.

### قواعد حیاتی حفظ ظاهر

این پلن **هیچ تغییر بصری یا رفتاری** نباید بدهد. مواردی که **باید** بی‌تغییر بمانند:

- `id="map"` و `class="h-[80lvh] rounded"` در `maps/map.blade.php` — تست‌های E2E روی `#map` و
  `clientWidth > 400` assert می‌کنند.
- `attribution: '&copy; Health-Dashboard'` و `className: 'map-tiles'`.
- شمارهٔ نشانگرها (۷۸۳ در داده‌های seed شده).
- ستون‌های خروجی و جدول‌ها — خارج از محدوده.
- نام‌های `window.searchRoute` / `window.reverseRoute` / `window.toggleGeoJson` /
  `window.toggleGeoJsonOn` / `window.toggleGeoJsonOff` / `window.toggleRoutingContainer` که در
  `x-on:click` استفاده می‌شوند — **باید حفظ شوند** یا `x-on:click`ها هم‌زمان به‌روز شوند.

---

## فاز 0 — baseline (بدون تغییر کد)

```bash
cd h-dashboard
git fetch --prune origin
git rev-list --left-right --count HEAD...origin/celin     # انتظار: 0 0
composer verify
```

اگر `composer verify` قرمز بود و به این پلن مربوط نبود → **STOP و گزارش**.

ثبت وضعیت تست نقشه‌ها **قبل** از تغییر (پایهٔ مقایسه):

```bash
bash scripts/e2e-test.sh tests/e2e/maps tests/e2e/organization/unit-map-boundary.spec.ts tests/e2e/reports/map-no-boundary.spec.ts
```

**Done:** `composer verify` سبز + شمارش پاس‌های E2E نقشه ثبت شد.

---

## فاز 1 — تست رگرسیون، قرمز شدن باگ (TDD)

**اول این فاز.** بدون آن، هیچ‌چیز ثابت نمی‌کند که معماری جدید کار می‌کند.

### چرا E2E فعلی این باگ را نمی‌گیرد

همهٔ specهای نقشه با `page.goto()` **مستقیم** می‌روند — همان مسیری که سالم است. هیچ‌کدام از منو
نمی‌آیند. پس باگ در CI نامرئی است و بدون تست جدید برمی‌گردد.

### فایل جدید: `tests/e2e/maps/spa-navigation.spec.ts`

الگو را از `tests/e2e/maps/layers.spec.ts` و `tests/e2e/shared/fixtures.ts` بگیر
(`import { test, expect, login } from '../shared/fixtures';`).

**نکتهٔ حیاتی:** روی صفحهٔ نقشه هرگز `await page.waitForLoadState('networkidle')` ننویس — Leaflet
بی‌نهایت tile می‌کشد و انتظار تایم‌اوت می‌شود. به‌جایش منتظر بمان:
`await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 })`.

```ts
import { test, expect, login } from '../shared/fixtures';

/**
 * Regression: /maps/point markers did not render when reached by SPA
 * navigation from another map page (window.map stale-instance race).
 *
 * Before the fix the host page bound its layers to the PREVIOUS page's
 * detached Leaflet instance, so `initMap()` created a new map and wiped the
 * layers. Measured: 0/6 runs rendered markers via menu navigation, while a
 * direct page.goto() always rendered 783.
 *
 * These tests navigate by clicking the real sidebar link (wire:navigate),
 * which is the path that failed.
 */

const MAP_PAGES = ['/maps/county', '/maps/unit', '/maps/route2'] as const;

/** Navigate via the sidebar so wire:navigate runs (not a full page load). */
async function navigateByMenu(page: import('@playwright/test').Page, href: string) {
  const link = page.locator(`a[href="${href}"]`).first();
  await link.waitFor({ state: 'visible', timeout: 15000 });
  await link.click();
  await page.waitForURL(`**${href}`, { timeout: 15000 });
  // Do NOT use networkidle here — Leaflet never goes idle on a map page.
  await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
  // Allow the store to hand back a live instance and layers to attach.
  await page.waitForTimeout(1500);
}

test.describe('maps — SPA navigation lifecycle (#028)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  for (const from of MAP_PAGES) {
    test(`${from} → /maps/point renders point markers`, async ({ page }) => {
      await page.goto(from);
      await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
      await page.waitForTimeout(1500);

      await navigateByMenu(page, '/maps/point');

      // THE REGRESSION ASSERTION: before the fix this count was 0.
      expect(
        await page.locator('#map .leaflet-marker-icon').count(),
        'markers must render when /maps/point is reached by SPA navigation',
      ).toBeGreaterThan(100);

      // The store must be bound to the container that is actually on screen.
      const bound = await page.evaluate(() => {
        const w = window as unknown as { map?: { getContainer(): HTMLElement } };
        const el = document.getElementById('map');
        return !!w.map && w.map.getContainer() === el;
      });
      expect(bound, 'leaflet instance must be bound to the live #map container').toBe(true);
    });
  }

  test('/maps/point → /maps/county then back renders polygons again', async ({ page }) => {
    await page.goto('/maps/point');
    await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(1500);

    await navigateByMenu(page, '/maps/county');
    await page.locator('a[href="/maps/point"]').first().click();
    await page.waitForURL('**/maps/point', { timeout: 15000 });
    await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(1500);

    // Round trip must not leak the previous page's layers or lose the new ones.
    expect(await page.locator('#map .leaflet-marker-icon').count()).toBeGreaterThan(100);
  });

  test('direct load still renders markers (no regression)', async ({ page }) => {
    await page.goto('/maps/point');
    await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(2500);
    expect(await page.locator('.leaflet-marker-icon').count()).toBeGreaterThan(100);
  });
});
```

**Done (قرمز):** تست جدید **باید** در کد فعلی **شکست بخورد** روی سه تست ناوبری.
اگر سبز شد، یعنی روش بازتولید اشتباه است — **STOP و گزارش**.

اجرای معتبر: `bash scripts/e2e-test.sh tests/e2e/maps/spa-navigation.spec.ts` — نه
`npx playwright test` خام (به `tests/e2e/.run-state.json` نیاز دارد که global-setup می‌سازد).

**پاکسازی اجباری** بعد از هر اجرای E2E (اسکریپت `trap` ندارد و با شکست، restore نمی‌کند):
```bash
[ -f .env.dev.bak ] && cp .env.dev.bak .env && rm -f .env.dev.bak
pgrep -f 'artisan serve --port=800[1]' | xargs -r kill
```

---

## فاز 2 — میان‌افزار (`resources/js/map-store.js`)

فایل جدید. **بدون تغییر ظاهر.** نکات:

- از Leaflet سراسری `window.L` استفاده کن (پروژه Leaflet را با `<script>` در `<head>` لود می‌کند،
  نه import).
- `use(el)` اگر `instance` زنده و کانتینرش همان `el` بود، **همان را برگرداند** (بازاستفاده).
- اگر `instance` زنده بود ولی کانتینرش دیگر متصل نبود، **اول نابودش کن** بعد بساز.
- `release(el)` فقط وقتی کانتینرِ ثبت‌شده همان `el` است نابود می‌کند (تا release دیررسِ یک
  صفحه، نقشهٔ صفحهٔ جدید را نکُشد).
- اگر `instance` تعریف‌شده نبود، `use()` **throw نکند** — یک `console.warn` بدهد. (مسیر خطا باید
  قابل تشخیص باشد، نه اینکه کل صفحه بی‌صدا خراب شود — دقیقاً همان درسی که این باگ داد.)

```js
/**
 * Leaflet lifecycle owner (issue #028).
 *
 * The map instance used to live on `window.map`, which is never cleared, so a
 * SPA navigation left the PREVIOUS page's instance in place. Host pages waited
 * for "window.map exists", which a detached instance satisfies — they bound
 * their layers to a map that `initMap()` then replaced. Measured 0/6 marker
 * renders via menu navigation.
 *
 * This store gives the instance an owner and a lifecycle: `use()` hands back a
 * map bound to the CURRENT container, `release()` destroys it. Nothing else
 * should create a Leaflet map.
 */
const MAP_ID = 'map';

function connected(el) {
  return !!el && document.body.contains(el);
}

function containerOf(instance) {
  return instance && typeof instance.getContainer === 'function'
    ? instance.getContainer()
    : null;
}

export function registerMapStore(Alpine) {
  Alpine.store(MAP_ID, {
    instance: null,
    container: null,

    /** Build or reuse a map bound to `el`. Returns the Leaflet instance. */
    use(el, options = {}) {
      if (!connected(el)) {
        console.warn('[map] container is not in the document', el);
        return null;
      }

      if (this.instance && containerOf(this.instance) === el) {
        return this.instance; // already ours — reuse
      }

      // Either nothing yet, or a stale instance from a previous page.
      if (this.instance) {
        this.release(null, { force: true });
      }

      const instance = L.map(el, options).setView(options.view, options.zoom);

      L.tileLayer(options.tileUrl, {
        attribution: options.attribution ?? '&copy; Health-Dashboard',
        className: 'map-tiles',
      }).addTo(instance);

      this.instance = instance;
      this.container = el;
      return instance;
    },

    /** Destroy the instance. `el` guards against a late release killing a newer map. */
    release(el, { force = false } = {}) {
      if (!this.instance) return;
      if (!force && el && this.container !== el) return;

      try {
        this.instance.off();
        this.instance.remove();
      } catch (e) {
        console.warn('[map] failed to tear down instance', e);
      }
      this.instance = null;
      this.container = null;
    },

    /** The live instance, or null. Never returns a detached map. */
    get() {
      if (this.instance && containerOf(this.instance) !== this.container) {
        return null;
      }
      return this.instance;
    },
  });
}
```

ثبت در `resources/js/bootstrap.js` (قبل از اینکه Alpine بالا بیاید، با `alpine:init`):

```js
import { registerMapStore } from './map-store';

document.addEventListener('alpine:init', () => {
  registerMapStore(window.Alpine);
});
```

**چرا `alpine:init` و نه اجرای مستقیم:** اجرای مستقیم در لحظهٔ بارگذاری ماژول، پیش از آنکه
`window.Alpine` وجود داشته باشد، شکست می‌خورد. گوش دادن به رویداد مستقل از ترتیب بارگذاری است
(اندازه‌گیری‌شده در فاز 0 — `alpine:init` قبل از اولین `x-data` می‌آید).

**Done:** `npm run build` بدون خطا. `git diff public/build/manifest.json` تغییر لازم را نشان دهد.

---

## فاز 3 — `maps/map.blade.php` تنها مالک `L.map()`

`x-data` را روی همان `div#map` بگذار تا چرخهٔ عمر به همان DOM گره بخورد (spike نشان داد destroy
قبل از init اجرا می‌شود).

**ساختار هدف** (ظاهر HTML باید همان بماند):

```blade
<div wire:ignore>
    <div id="map" class="h-[80lvh] rounded"
         x-data="{
             init() {
                 this.map = $store.map.use(this.$el, {
                     view: {{ $setview }},
                     zoom: {{ $zoom }},
                     tileUrl: '{{ $map_tile_template }}',
                 });
                 if (this.map) {
                     setTimeout(() => this.map.invalidateSize(), 100);
                     this._onResize = () => this.map.invalidateSize();
                     window.addEventListener('resize', this._onResize);
                 }
             },
             destroy() {
                 if (this._onResize) window.removeEventListener('resize', this._onResize);
                 $store.map.release(this.$el);
             },
         }"></div>
</div>
```

قواعد این فاز:

- `window.map = map` **حذف شود.** `map.blade.php:62` باید دیگر هیچ انتساب سراسری نداشته باشد.
- آرایهٔ `['markersLayer','linesLayer','geojsonLayers','countyLayers']` که در `map.blade.php:67`
  پاک می‌شد **حذف شود** — مالکیت لایه‌ها به مصرف‌کننده منتقل شده (فاز 4)، و `release()` نقشه را
  کامل نابود می‌کند.
- `invalidateSize` و شنوندهٔ `resize` **حفظ شوند** — `AGENTS.md` آن‌ها را برای رفع مشکل نیمه‌عرض
  الزامی می‌داند و تست `map is not half-width` روی `clientWidth > 400` assert می‌کند.
- بلوک `@script` فعلی `initMap`/`waitForEl` **حذف شود** — جایگزین `x-data` شده.
- `@assets` استایل `#map` **دست‌نخورده**.

**سازگاری با تست موجود:** `tests/Feature/MapsMapLivewireTest.php:86-89` این رشته‌ها را در HTML
assert می‌کند: `initMap`، `invalidateSize`، `resize`، `_leaflet_id`. سه‌تای اول باقی می‌مانند
(`invalidateSize` و `resize` مستقیم در `x-data` هستند). **`initMap` و `_leaflet_id` دیگر وجود
نخواهند داشت.** آن تست **باید** به‌روز شود تا به‌جای آن‌ها `x-data` و `$store.map` را assert کند —
این یک تغییر تستِ ناشی از تغییر معماری است، نه خفیف‌کردن تست. اگر فکر می‌کنی این مجاز نیست،
**STOP و گزارش** — سپس شاید راه‌حل کم‌تهاجم‌تری لازم باشد.

**Done:** `composer verify` سبز، به‌روزرسانی تست، و تأیید دستی در مرورگر که `/maps/point` لود
مستقیم هنوز ۷۸۳ نشانگر می‌دهد.

---

## فاز 4 — مهاجرت مصرف‌کننده‌ها

هر صفحه به‌جای `waitForMap(...)` الگوی `use()` را می‌گیرد. **نمونهٔ مرجع — `maps/point.blade.php`:**

```js
// قبل: انتظار با شرط وجودِ ناکافی
function waitForMap(callback) {
    var tries = 0;
    function check() {
        if (window.map && typeof window.map.getSize === 'function') { callback(); }
        else if (++tries > 50) { console.error('Map not ready within 10s'); }
        else { setTimeout(check, 200); }
    }
    check();
}

// بعد: مالکیت صریح
(function () {
    const el = document.getElementById('map');
    const instance = window.Alpine.store('map').use(el);
    if (!instance) return;                 // مسیر خطا: قابل تشخیص، نه خاموش

    const markersLayer = L.layerGroup().addTo(instance);
    const linesLayer   = L.layerGroup().addTo(instance);

    function renderMarkers(locations) {
        markersLayer.clearLayers();
        linesLayer.clearLayers();
        /* … منطق فعلی، با markersLayer/linesLayer جایگزین window.* … */
    }

    renderMarkers({{ Js::from($location) }});
    Livewire.on('locations-updated', ({ locations }) => renderMarkers(locations));
})();
```

نکات مهم مهاجرت:

- **`@script` می‌ماند**، ولی دیگر منتظر چیزی نیست؛ فقط `use()` را صدا می‌زند. ترتیب دیگر مهم نیست.
- نام‌های `window.*` که در `x-on:click` به آن‌ها ارجاع می‌شود (`toggleGeoJson`,
  `toggleGeoJsonOn/Off`, `searchRoute`, `reverseRoute`, `toggleRoutingContainer`) **باید حفظ شوند**
  یا `x-on:click`ها هم‌زمان به‌روز شوند. `maps/county.blade.php:65` و `maps/route2.blade.php:53-64`
  به این‌ها وابسته‌اند.
- در `maps/route.blade.php` و `maps/route2.blade.php`، `window.routingControl` و بلوک «Destroy any
  existing routing control» باید به `release()` واگذار شود — کنترل روی نقشهٔ مرده بی‌معناست.
- `maps/unit.blade.php:352` یک باگ جدا دارد: `map.fitBounds(...)` به‌جای `window.map.fitBounds(...)`
  (تعریف‌فرض window). **هنگام مهاجرت به `instance.fitBounds(...)` اصلاح شود** — این در محدوده است
  چون همان خط بازنویسی می‌شود.
- `reports/map-no-boundary.blade.php:169` از `Object.values(markers).flat()` استفاده می‌کند؛ فقط
  مرجع map را عوض کن، منطق دست‌نخورده.

**ترتیب:** یکی‌یکی مهاجرت بده و بعد از هر کدام تست کن. `point` اول (ریپروی اصلی)، بعد
`county`، `unit`، `map-no-boundary`، بعد `route` و `route2` (پرریسک‌ترین، چون `routingControl`
دارند).

**Done هر صفحه:** لود مستقیم سالم + ناوبری SPA از دو مبدأ مختلف سالم + `composer test` سبز.

---

## فاز 5 — تأیید، مستندسازی، کامیت

### تأیید دستی (تکرار دقیق روش فاز 0)

```bash
bash scripts/e2e-test.sh tests/e2e/maps tests/e2e/organization/unit-map-boundary.spec.ts tests/e2e/reports/map-no-boundary.spec.ts
```

انتظار: تست جدید `spa-navigation.spec.ts` **سبز**، و هیچ تست موجودی **بدون تغییر** پاس نشود.
`unit-map-boundary.spec.ts` خاصاً مهم است چون به `window._drawnItems` وابسته است — آن فایل خارج
از محدودهٔ تغییر است، پس باید **دست‌نخورده** پاس شود.

پاکسازی اجباری (بخش پایانی فاز 1 را تکرار کن).

### تست واحد

تست JS واحد در پروژه وجود ندارد و راه‌اندازی زیرساخت جدید خارج از محدوده است. تأیید از راه
E2E + بازرسی دستی مرورگر انجام می‌شود. **اگر این را کافی نمی‌دانی → STOP و گزارش.**

### `composer verify`

```bash
composer phpstan-baseline
git diff --stat phpstan-baseline.neon    # باید ۰ addition باشد
composer verify
```

### مستندسازی — `AGENTS.md`

یک بخش کوتاه اضافه کن (در کنار بخش‌های نقشهٔ موجود) با این محتوا:

- نمونهٔ Leaflet در `Alpine.store('map')` زندگی می‌کند، نه روی `window.map`.
- `maps/map.blade.php` تنها جایی است که `L.map()` صدا می‌زند.
- صفحه‌های میزبان `use()` را صدا می‌زنند و به **همان** نمونه می‌چسبند.
- `destroy()` → `release()`: اگر `x-data` نداشته باشی، نقشهٔ قبلی زنده می‌ماند و **همین کلاس از
  باگ** برمی‌گردد.
- ردیف gotcha: «`window.map` را برنگردان» — `AGENTS.md` از قبل برای چند مورد مشابه gotcha دارد.

یک ردیف در جدول Gotchas Quick Reference هم اضافه کن.

### کامیت

پیام‌های معنادار، `package-lock.json` staged نشود:

```bash
git add resources/js/map-store.js resources/js/bootstrap.js \
        resources/views/components/layouts/app.blade.php \
        resources/views/livewire/maps/map.blade.php \
        tests/e2e/maps/spa-navigation.spec.ts AGENTS.md
git commit -m "fix(maps): own Leaflet lifecycle in an Alpine store (issue #028)"
# صفحات مصرف‌کننده در کامیت جداگانه یا همین، بسته به اندازهٔ تغییر
git push origin HEAD:refs/heads/celin
git ls-remote origin refs/heads/celin
```

**Done:** `git status` تمیز (جز `package-lock.json` که طبق قاعده unstaged می‌ماند)، `celin` در
ریموت با کامیت مورد انتظار، `composer verify` سبز، E2E نقشه‌ها سبز.

---

## فهرست Escape Hatch — کجا باید متوقف شوی

| وضعیت | اقدام |
|---|---|
| تست فاز 1 با کد فعلی **سبز** شد | روش بازتولید اشتباه است → STOP و گزارش |
| `composer verify` قبل از شروع قرمز بود | مشکل از این پلن نیست → STOP و گزارش |
| تست موجودی (`MapsMapLivewireTest` یا هر E2E نقشه) بدون به‌روزرسانی شکست خورد | معماری تغییر رفتار داده → STOP؛ تست را خفیف نکن |
| `map-dashboard` یا `units/map` هم به این مشکل خورد | خارج از محدوده → STOP و گزارش، تصمیم جداگانه |
| `phpstan-baseline.neon` بعد از regenerate اضافه داشت | خطای واقعی سرکوب شده → STOP |
| مجبور شدی به `test_standalone_renders` یا مشابه دست بزنی | ظاهر/قرارداد نقض شده → STOP |
| `npm run build` بعد از `bootstrap.js` شکست خورد | build باید قبل از تست‌ها سبز باشد → گزارش |

---

## یادداشت نگهداری

- **افزودن صفحهٔ نقشهٔ جدید:** `maps/map.blade.php` را embed کن و `Alpine.store('map').use(el)`
  صدا بزن. `waitForMap` دیگر الگوی درست نیست — اگر آن را کپی کردی، این باگ برمی‌گردد.
- **چرا `window.map` حذف شد و جایگزینش نشد:** هر مصرف‌کننده‌ای که هنوز `window.map` بخواند، به
  نمونهٔ مرده چسبیده و همان باگ را برمی‌گرداند. وجود نداشتنش عمدی است — `grep window.map` باید
  فقط `tests/e2e/organization/unit-map-boundary.spec.ts` را نشان دهد (آن یکی به `unitMap`
  مربوط است، نه `window.map`).
- **ریسک باقی‌مانده:** تأیید دستی مرورگر برای `/maps/route` و `/maps/route2` لازم است — آن‌ها به
  سرویس routing بیرونی (`127.0.0.1:5000` / `:8088`) وابسته‌اند که در این محیط نیست. تست‌های
  E2E آن‌ها فقط وجود نقشه را assert می‌کنند، نه مسیریابی واقعی.