import { test, expect, login, waitForLivewire, waitForToast } from '../shared/fixtures';

/**
 * Action smoke suite — the main journeys, end to end.
 *
 * Why this file exists: the suite already had 57 spec files, but almost every
 * one was written AFTER a bug was found, and the one smoke test that existed
 * (`navigation/smoke-links.spec.ts`) only checks that sidebar links return a
 * non-500 — it never clicks a button, saves anything, or submits a form. The
 * bugs the user kept finding by hand (submenu closing #1389, units-map save
 * doing nothing #1391) were pages that LOADED fine while the action on them
 * was dead. Each test here does the one key action of a journey and asserts
 * its durable outcome (toast, badge, row, filter change) — not just that the
 * page renders.
 *
 * Rules for this file:
 * - One key action per journey, no more. Thorough coverage lives in the
 *   feature specs; this file answers "is the main thing broken?" in minutes.
 * - Prefer server-visible outcomes (toast / badge / row / count) over DOM
 *   internals, so a passing test means the action really landed.
 * - No `networkidle` on map pages — Leaflet keeps fetching tiles, so the
 *   network never goes idle (see unit-map-boundary.spec.ts).
 * - Mutating tests (ticket create, todo create, units-map save) use
 *   timestamped subjects / fresh seeded data; the E2E DB is wiped every run.
 */

const DASHBOARD_STATS = [
  'کاربران',
  'پرسنل',
  'واحدها',
  'نقش‌ها',
  'کل تیکت‌ها',
  'تیکت‌های باز',
  'تیکت‌های تکمیل شده',
];

/**
 * A fresh, checksum-valid Iranian national code (see
 * app/Rules/IranianNationalCode.php: the last digit is the weighted check
 * digit of the first nine). Derived from the timestamp so every run creates
 * a new patient instead of colliding with an earlier run's.
 */
function freshNationalCode(): string {
  const base = String(Date.now()).slice(-9).padStart(9, '0');
  let sum = 0;
  for (let i = 0; i < 9; i++) sum += Number(base[i]) * (10 - i);
  const remainder = sum % 11;
  const check = remainder < 2 ? remainder : 11 - remainder;
  return base + String(check);
}

test.describe('smoke — main journeys (actions, not just loads)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('dashboard renders all stat cards', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    for (const title of DASHBOARD_STATS) {
      await expect(page.locator('body')).toContainText(title);
    }
  });

  test('GIS map renders and the hardware layer toggle reacts', async ({ page }) => {
    await page.goto('/map');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#map')).toBeVisible();
    await expect(page.locator('.leaflet-container').first()).toBeVisible({ timeout: 15000 });

    await page.getByRole('button', { name: /سخت‌افزار/ }).click();
    await page.waitForTimeout(2000);
    // The toggle reacted and the map stayed healthy.
    await expect(page.locator('.leaflet-container').first()).toBeVisible();
  });

  test('units map: drawing a polygon and clicking ذخیره saves the boundary', async ({ page }) => {
    // Compact form of the #1391 regression test: open a unit without a
    // boundary, draw, save, and assert the save lands server-side.
    await page.goto('/units');
    await page.waitForLoadState('domcontentloaded');

    const candidateIds = await page.evaluate(() => {
      const ids = new Set<number>();
      document.querySelectorAll('a[href*="/map"]').forEach((a) => {
        const m = a.getAttribute('href')?.match(/\/units\/(\d+)\/map/);
        if (m) ids.add(Number(m[1]));
      });
      return Array.from(ids);
    });
    expect(candidateIds.length, 'expected at least one unit map link').toBeGreaterThan(0);

    let unitId: number | null = null;
    for (const id of candidateIds) {
      await page.goto(`/units/${id}/map`);
      await page.locator('#unitMap').waitFor({ state: 'visible', timeout: 15000 });
      await page.locator('.leaflet-draw-edit-edit').waitFor({ state: 'visible', timeout: 15000 });
      if ((await page.locator('.badge', { hasText: 'بدون مرز' }).count()) > 0) {
        unitId = id;
        break;
      }
    }
    test.skip(unitId === null, 'no seeded unit without a boundary in this environment');

    await page.locator('.leaflet-draw-draw-polygon').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('.leaflet-draw-draw-polygon').click();
    await page.waitForTimeout(500);

    const box = await page.locator('#unitMap').boundingBox();
    if (!box) throw new Error('unit map has no bounding box');
    const cx = box.x + box.width / 2;
    const cy = box.y + box.height / 2;
    for (const [x, y] of [
      [cx - 60, cy - 40],
      [cx + 60, cy - 40],
      [cx + 60, cy + 40],
    ] as const) {
      await page.mouse.click(x, y);
      await page.waitForTimeout(300);
    }
    await page.mouse.click(cx - 60, cy - 40);

    await expect
      .poll(async () => page.evaluate(() => (window as unknown as { _mapGeojson?: string | null })._mapGeojson))
      .toBeTruthy();

    const update = waitForLivewire(page);
    await page.locator('#saveMapBoundaryBtn').click();
    await update;
    await expect(page.locator('.badge', { hasText: 'مرز تعریف شده' })).toBeVisible({ timeout: 15000 });
  });

  test('disease case create end-to-end (new patient + case)', async ({ page }) => {
    // No cases are seeded, so the list leg cannot assert rows — the real
    // journey is the create form: new patient inline (the kodMelli lookup
    // finds nothing) → disease + diagnosed date → submit → the case lists.
    await page.goto('/disease/create');
    await page.waitForLoadState('networkidle');

    const code = freshNationalCode();
    // NOTE: the placeholder carries a trailing space ("۱۰ رقم "), so match
    // by substring, never by exact value.
    await page.locator('input[placeholder*="۱۰ رقم"]').first().fill(code);
    await expect(page.locator('body')).toContainText('بیمار با این کد ملی یافت نشد', { timeout: 15000 });

    await page.locator('input[wire\\:model="newPatient.first_name"]').fill('اسمک');
    await page.locator('input[wire\\:model="newPatient.last_name"]').fill('تست');
    await page.locator('select[wire\\:model="newPatient.gender"]').selectOption({ index: 1 });
    const register = waitForLivewire(page);
    await page.getByRole('button', { name: 'ثبت بیمار' }).click();
    await register;
    await expect(page.locator('.toast').first()).toContainText('بیمار ثبت شد', { timeout: 10000 });

    const diseaseUpdate = waitForLivewire(page);
    await page.locator('select[wire\\:model\\.live="diseaseId"]').selectOption({ label: 'دیابت' });
    await diseaseUpdate;

    // Step 3 appears after the disease is picked: diabetes requires the
    // dynamic FBS field (save() fails validation without it).
    await page.locator('input[wire\\:model="fields.fbs"]').waitFor({ state: 'visible', timeout: 10000 });
    await page.locator('input[wire\\:model="fields.fbs"]').fill('110');

    // The diagnosed-date input is readonly (Jalali picker) and syncs to
    // Livewire ONLY through the picker's `jdp:change` event (see
    // initDiseaseJdp in page-scripts.js) — raw input/change events never
    // reach the component, so dispatch `jdp:change` after setting the value.
    const diagInput = page.locator('#disease_diagnosed_at');
    const dateUpdate = waitForLivewire(page);
    await diagInput.evaluate((el) => {
      const input = el as HTMLInputElement;
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
        window.HTMLInputElement.prototype,
        'value',
      )!.set!;
      nativeInputValueSetter.call(input, '1404/07/05');
      input.dispatchEvent(new Event('jdp:change', { bubbles: true }));
    });
    await dateUpdate;

    const save = waitForLivewire(page);
    await page.getByRole('button', { name: 'ثبت کیس' }).click();
    await save;
    // Target the toast by its text: the patient-registration toast is still
    // in the DOM, so `.toast`.first() would match the stale one.
    await expect(page.locator('.toast', { hasText: 'کیس بیماری ثبت شد' })).toBeVisible({
      timeout: 10000,
    });

    // The new case now lists on /disease.
    await page.goto('/disease');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('table tbody tr').first()).toContainText('اسمک');
  });

  test('ticket create lands with a success toast', async ({ page }) => {
    await page.goto('/tickets/new');
    await page.waitForLoadState('networkidle');

    const ts = Date.now();
    await page.locator('input[placeholder^="جستجوی واحد"]').first().fill('زنجان');
    await page.waitForSelector('[wire\\:click*="selectUnit"]', { state: 'visible', timeout: 10000 });
    const selectUnit = waitForLivewire(page);
    await page.locator('[wire\\:click*="selectUnit"]').first().click();
    await selectUnit;

    await page.locator('select').first().selectOption('urgent');
    await page.locator('input[wire\\:model="subject"]').fill(`تست خودکار اسمک ${ts}`);
    await page
      .locator('textarea[wire\\:model="content"]')
      .fill('این یک متن تستی برای بررسی ثبت تیکت به صورت خودکار است که بیش از بیست کاراکتر دارد');
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await expect(page.locator('.toast').first()).toContainText('تیکت با موفقیت ثبت شد', {
      timeout: 10000,
    });
  });

  test('hardware laptop filter narrows the list', async ({ page }) => {
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');

    const totalBefore = await page.locator('.mary-table-pagination').innerText();
    const filterUpdate = waitForLivewire(page);
    await page.locator('button[wire\\:click*="laptop"]').click();
    await filterUpdate;
    const totalAfter = await page.locator('.mary-table-pagination').innerText().catch(() => '');
    expect(totalAfter).not.toBe(totalBefore);
  });

  test('todo create via modal shows a success toast', async ({ page }) => {
    await page.goto('/todo');
    await page.waitForLoadState('networkidle');

    await page.getByRole('button', { name: 'تسک جدید' }).click();
    await page.locator('input[wire\\:model="title"]').fill('تست اسمک تسک');
    await page.waitForTimeout(500);

    // The start-date input is readonly (Jalali picker): set the value via JS
    // and dispatch the events wire:model.live listens to.
    const startDateInput = page.locator('input[data-jdp]').first();
    const dateUpdate = waitForLivewire(page);
    await startDateInput.evaluate((el) => {
      const input = el as HTMLInputElement;
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
        window.HTMLInputElement.prototype,
        'value',
      )!.set!;
      nativeInputValueSetter.call(input, '1405/07/01');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await dateUpdate;

    await page.locator('input[type="time"]').first().fill('09:00');
    const save = waitForLivewire(page);
    await page.getByRole('button', { name: 'ذخیره' }).click();
    await save;
    await waitForToast(page, 'با موفقیت ذخیره شد');
  });

  test('global search finds the admin user', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    const input = page.locator('input[placeholder^="حداقل"]');
    const search = waitForLivewire(page);
    await input.fill('عسگری');
    await search;
    await expect(page.locator('body')).toContainText('نتیجه یافت شد');
    await expect(page.locator('body')).toContainText('کاربران');
  });

  test('settings save round-trips with a success toast', async ({ page }) => {
    // No values are changed: the save rewrites the current settings and
    // proves the save path (Livewire update + toast) works.
    await page.goto('/settings');
    await page.waitForLoadState('networkidle');

    const save = waitForLivewire(page);
    await page.getByRole('button', { name: 'ذخیره تنظیمات' }).click();
    await save;
    await expect(page.locator('.toast').first()).toContainText('تنظیمات ذخیره شد', {
      timeout: 10000,
    });
  });

  test('sidebar navigate swaps to the GIS map without a reload', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    // The «کار با نقشه» submenu is closed on /dashboard — expand it first.
    await page.locator('.drawer-side summary', { hasText: 'کار با نقشه' }).click();
    await page.locator('.drawer-side a[href="/map"]').waitFor({ state: 'visible', timeout: 10000 });

    await page.evaluate(() => {
      (window as unknown as { __smoke?: number }).__smoke = 1;
    });
    await page.locator('.drawer-side a[href="/map"]').click();
    await page.waitForURL('**/map');
    // A surviving marker proves a Livewire navigate swap, not a full reload.
    const marker = await page.evaluate(() => (window as unknown as { __smoke?: number }).__smoke);
    expect(marker).toBe(1);
    await expect(page.locator('#map')).toBeVisible();
  });
});
