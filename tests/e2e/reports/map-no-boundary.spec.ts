import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 009 — Reports persons + map-no-boundary
 * Probed DOM facts:
 * - /reports/persons → "گزارش پرسنل", columns # | کد ملی | نام | واحد | تحصیلات | سمت | استخدام
 * - /reports/map-no-boundary → "نقاط فاقد مرز در نقشه": stat cards (کل, دارای مختصات, بدون مختصات)
 *   + "واحدهای فاقد مرز و مختصات" table (columns # | نام | نوع | منطقه)
 *
 * NOTE: exact counts (290/241/49) removed — they depend on seed data and break
 * when seeders change. Tests now verify structural presence only.
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

  test('renders units-without-boundary summary cards', async ({ page }) => {
    await expect(page.locator('body')).toContainText('نقاط فاقد مرز در نقشه');
    // Verify all three stat card labels exist (counts are relative, not hardcoded)
    await expect(page.locator('body')).toContainText('کل');
    await expect(page.locator('body')).toContainText('دارای مختصات');
    await expect(page.locator('body')).toContainText('بدون مختصات');
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
