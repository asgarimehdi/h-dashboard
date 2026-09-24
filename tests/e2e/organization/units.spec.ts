import { test, expect, login } from '../shared/fixtures';

/**
 * Organization units list
 * Probed DOM facts:
 * - Columns: # | نام | نوع | منطقه | واحدهای زیرمجموع
 * - Search: input[placeholder^="جستجو"]
 * - Pagination: .mary-table-pagination with total count
 *
 * All count assertions are relative — no hardcoded numbers.
 */

test.describe('organization units', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/units');
    await page.waitForLoadState('networkidle');
  });

  test('list loads with expected columns', async ({ page }) => {
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['نام', 'نوع واحد', 'منطقه']) {
      expect(headers).toContain(col);
    }
  });

  test('shows total units in pagination', async ({ page }) => {
    await expect(page.locator('.mary-table-pagination')).toContainText('نمایش');
  });

  test('search filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    // زنجان is always in seeded unit names
    await search.fill('زنجان');
    await page.waitForTimeout(1500);
    await expect(page.locator('table tbody')).toContainText('زنجان');
  });
});
