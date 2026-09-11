import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 009 — Reports units
 * Probed DOM facts:
 * - /reports/units → "گزارش واحدها و مراکز", unit-type filter (18 types) + table
 *   columns: # | نام | نوع | منطقه | مرز
 * - 832 rows (all units)
 */

test.describe('reports units', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/reports/units');
    await page.waitForLoadState('networkidle');
  });

  test('units report loads with type filter and table', async ({ page }) => {
    await expect(page.locator('body')).toContainText('گزارش واحدها و مراکز');
    await expect(page.locator('body')).toContainText('نوع واحد');

    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['نام', 'نوع', 'منطقه', 'مرز']) {
      expect(headers).toContain(col);
    }
  });

  test('units report shows unit rows', async ({ page }) => {
    // The table lists all units (default "همه انواع") — assert a known root unit
    // name renders rather than relying on a raw tbody row count (pagination varies).
    await expect(page.locator('body')).toContainText('وزارت بهداشت');
  });
});