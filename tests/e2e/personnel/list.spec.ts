import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 006 — Personnel list
 * Probed DOM facts:
 * - Headers: # | کد ملی | نام | نام خانوادگی | تحصیلات | استخدام | سمت | ردیف سازمانی | واحد
 * - 318 total records, 20/page (paginated)
 * - Search: input[placeholder^="جستجو"] (multi-term AND over name/n_code/unit)
 * - Filters toggle: button[wire\:click="$toggle('showFilters')"] reveals a panel
 *   with سمت/تحصیلات/استخدام/ردیف سازمانی selects + واحد picker + "پاک کردن فیلترها"
 * - Filter selects use wire:model.live (e.g. select[wire\:model\.live="filter_s_id"]),
 *   driven via selectOption({ index }); wait ~1500ms after change for Livewire round-trip
 * - NOTE: unit filter skipped — 'انتخاب واحد' opens filterUnitModal, an Alpine
 *   tree picker whose search input is x-model (not Livewire); too flaky for E2E
 */

test.describe('personnel list', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/kargozini/persons');
    await page.waitForLoadState('networkidle');
  });

  test('list loads with expected columns', async ({ page }) => {
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['کد ملی', 'نام', 'نام خانوادگی', 'تحصیلات', 'استخدام', 'سمت', 'ردیف سازمانی', 'واحد']) {
      expect(headers).toContain(col);
    }
  });

  test('shows 318 total records', async ({ page }) => {
    await expect(page.locator('.mary-table-pagination')).toContainText('318');
  });

  test('search by name filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    await search.fill('عسگری');
    await page.waitForTimeout(1500);
    await expect(page.locator('table tbody')).toContainText('مهدی عسگری');
  });

  test('search by n_code filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    await search.fill('4411015056');
    await page.waitForTimeout(1500);
    await expect(page.locator('table tbody')).toContainText('4411015056');
  });

  test('filters panel opens with سمت/تحصیلات/استخدام/ردیف selects', async ({ page }) => {
    await page.locator('button[wire\\:click="$toggle(\'showFilters\')"]').click();
    await page.waitForTimeout(800);
    await expect(page.locator('body')).toContainText('پاک کردن فیلترها');
  });

  test('filter by semat narrows results', async ({ page }) => {
    await page.locator('button[wire\\:click="$toggle(\'showFilters\')"]').click();
    await page.waitForTimeout(800);
    await page.locator('select[wire\\:model\\.live="filter_s_id"]').selectOption({ index: 1 });
    await page.waitForTimeout(1500);
    // Probed: کارشناس آی تی matches 3 rows — pagination loses the "از 318 نتیجه" line
    await expect(page.locator('.mary-table-pagination').first()).not.toContainText('318');
    const rows = await page.locator('table tbody tr').count();
    expect(rows).toBeGreaterThan(0);
    expect(rows).toBeLessThan(20);
  });

  test('filter by tahsil narrows results', async ({ page }) => {
    await page.locator('button[wire\\:click="$toggle(\'showFilters\')"]').click();
    await page.waitForTimeout(800);
    // Probed: index 1 (بیسواد) matches all 318 rows, so use index 2 (دیپلم);
    // seeded data has no دیپلم rows → empty tbody proves the filter applied
    await page.locator('select[wire\\:model\\.live="filter_t_id"]').selectOption({ index: 2 });
    await page.waitForTimeout(1500);
    await expect(page.locator('.mary-table-pagination').first()).not.toContainText('318');
    expect(await page.locator('table tbody tr').count()).toBe(0);
  });

  test('clear filters restores 318 total', async ({ page }) => {
    await page.locator('button[wire\\:click="$toggle(\'showFilters\')"]').click();
    await page.waitForTimeout(800);
    await page.locator('select[wire\\:model\\.live="filter_s_id"]').selectOption({ index: 1 });
    await page.waitForTimeout(1500);
    await expect(page.locator('.mary-table-pagination').first()).not.toContainText('318');
    await page.locator('button:has-text("پاک کردن فیلترها")').first().click();
    await page.waitForTimeout(1500);
    await expect(page.locator('.mary-table-pagination').first()).toContainText('318');
    expect(await page.locator('table tbody tr').count()).toBe(20);
  });
});
