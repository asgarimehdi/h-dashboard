import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 009 — Reports persons + map-no-boundary
 * Probed DOM facts:
 * - /reports/persons → "گزارش پرسنل", columns # | کد ملی | نام | واحد | تحصیلات | سمت | استخدام (318 rows)
 * - /reports/map-no-boundary → "نقاط فاقد مرز در نقشه": stat cards (کل 290، دارای مختصات 241،
 *   بدون مختصات 49) + "واحدهای فاقد مرز و مختصات" table (columns # | نام | نوع | منطقه)
 */

test.describe('reports persons', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/reports/persons');
    await page.waitForLoadState('networkidle');
  });

  test('persons report renders with columns', async ({ page }) => {
    await expect(page.locator('body')).toContainText('گزارش پرسنل');
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['کد ملی', 'نام', 'واحد', 'تحصیلات', 'سمت', 'استخدام']) {
      expect(headers).toContain(col);
    }
  });
});

test.describe('reports map-no-boundary', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/reports/map-no-boundary');
    await page.waitForLoadState('networkidle');
  });

  test('renders units-without-boundary summary (290)', async ({ page }) => {
    await expect(page.locator('body')).toContainText('نقاط فاقد مرز در نقشه');
    await expect(page.locator('body')).toContainText('290'); // کل واحدهای فاقد مرز
    await expect(page.locator('body')).toContainText('241'); // دارای مختصات
    await expect(page.locator('body')).toContainText('49');  // بدون مختصات
  });

  test('renders no-boundary+no-coordinates table', async ({ page }) => {
    await expect(page.locator('body')).toContainText('واحدهای فاقد مرز و مختصات');
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['نام', 'نوع', 'منطقه']) {
      expect(headers).toContain(col);
    }
  });
});