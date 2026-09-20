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
    await expect(page.locator('.mary-table-pagination')).toBeVisible();
  });

  test('search filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    // Use keyboard typing to trigger Livewire's wire:model.live.debounce properly
    await search.click();
    await search.pressSequentially('وزارت', { delay: 50 });
    // Wait for Livewire round-trip to complete and table to update
    await page.waitForFunction(
      () => !document.querySelector('.wire-loading'),
      { timeout: 10000 },
    );
    await page.waitForTimeout(500);
    await expect(page.locator('table tbody tr').first()).toContainText('وزارت');
  });
});
