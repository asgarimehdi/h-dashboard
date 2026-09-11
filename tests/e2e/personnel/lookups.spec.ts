import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 006 — Personnel lookup tables (list-only)
 * Probed DOM facts — each renders a table with headers # | عنوان (+ actions):
 * - /kargozini/estekhdams  → 5 rows  (استخدام)
 * - /kargozini/tahsils     → 6 rows  (تحصیلات)
 * - /kargozini/radifs      → 55 rows (ردیف سازمانی, paginated 20/page)
 * - /kargozini/semats      → 56 rows (سمت‌ها, paginated 20/page)
 */

const LOOKUPS = [
  { name: 'استخدام', path: '/kargozini/estekhdams', total: 5 },
  { name: 'تحصیلات', path: '/kargozini/tahsils', total: 6 },
  { name: 'ردیف سازمانی', path: '/kargozini/radifs', total: 55 },
  { name: 'سمت‌ها', path: '/kargozini/semats', total: 56 },
];

test.describe('personnel lookup tables', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  for (const lookup of LOOKUPS) {
    test(`${lookup.name} renders table`, async ({ page }) => {
      await page.goto(lookup.path);
      await page.waitForLoadState('networkidle');

      const headers = await page.locator('table thead th').evaluateAll((th) =>
        th.map((x) => x.textContent!.trim()),
      );
      expect(headers).toContain('عنوان');

      const rows = await page.locator('table tbody tr').count();
      expect(rows).toBeGreaterThan(0);
    });

    test(`${lookup.name} shows total count (${lookup.total})`, async ({ page }) => {
      await page.goto(lookup.path);
      await page.waitForLoadState('networkidle');
      // MaryUI pagination footer prints the running total.
      await expect(page.locator('body')).toContainText(String(lookup.total));
    });
  }
});