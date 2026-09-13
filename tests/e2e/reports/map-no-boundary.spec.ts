import { test, expect, login } from '../shared/fixtures';

/**
 * Reports: persons + map-no-boundary
 * Probed DOM facts:
 * - /reports/persons → "گزارش پرسنل", columns # | کد ملی | نام | واحد | تحصیلات | سمت | استخدام
 * - /reports/map-no-boundary → "نقاط فاقد مرز در نقشه": stat cards + table
 *
 * All count assertions are relative — no hardcoded numbers.
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

  test('renders units-without-boundary summary', async ({ page }) => {
    await expect(page.locator('body')).toContainText('نقاط فاقد مرز در نقشه');
    // Seeded data has units without boundaries — stat cards show counts
    // Just verify the page rendered with some numeric values (not exact counts)
    const body = await page.locator('body').innerText();
    expect(body).toMatch(/\d+/);
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
