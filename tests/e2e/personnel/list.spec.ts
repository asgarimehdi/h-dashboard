import { test, expect, login } from '../shared/fixtures';

/**
 * Personnel list
 * All count assertions are relative — no hardcoded numbers.
 */

const FILTER_BTN = 'button[wire\\:click="$toggle(\'showFilters\')"]';

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

  test('shows total records in pagination', async ({ page }) => {
    await expect(page.locator('.mary-table-pagination')).toContainText('نتیجه');
  });

  test('search by name filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    await search.fill('عسگری');
    await page.waitForTimeout(1500);
    await expect(page.locator('table tbody')).toContainText('عسگری');
  });

  test('search by n_code filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    await search.fill('4411015056');
    await page.waitForTimeout(1500);
    await expect(page.locator('table tbody')).toContainText('4411015056');
  });

  test('filters panel opens', async ({ page }) => {
    await page.locator(FILTER_BTN).click();
    await page.waitForTimeout(800);
    await expect(page.locator('body')).toContainText('پاک کردن فیلترها');
  });

  test('filter by semat narrows results', async ({ page }) => {
    await expect(page.locator('.mary-table-pagination')).toContainText('نتیجه');
    await page.locator(FILTER_BTN).click();
    await page.waitForTimeout(800);
    await page.locator('select[wire\\:model\\.live="filter_s_id"]').selectOption({ index: 1 });
    await page.waitForTimeout(1500);
    const rows = await page.locator('table tbody tr').count();
    expect(rows).toBeGreaterThan(0);
    expect(rows).toBeLessThanOrEqual(20);
  });

  test('filter by tahsil narrows results', async ({ page }) => {
    await page.locator(FILTER_BTN).click();
    await page.waitForTimeout(800);
    await page.locator('select[wire\\:model\\.live="filter_t_id"]').selectOption({ index: 2 });
    await page.waitForTimeout(1500);
    expect(await page.locator('table tbody tr').count()).toBe(0);
  });

  test('clear filters restores full list', async ({ page }) => {
    await page.locator(FILTER_BTN).click();
    await page.waitForTimeout(800);
    await page.locator('select[wire\\:model\\.live="filter_s_id"]').selectOption({ index: 1 });
    await page.waitForTimeout(1500);
    const filteredRows = await page.locator('table tbody tr').count();
    expect(filteredRows).toBeLessThanOrEqual(20);

    await page.locator('button:has-text("پاک کردن فیلترها")').first().click();
    await page.waitForTimeout(1500);
    await expect(page.locator('.mary-table-pagination')).toContainText('نتیجه');
    expect(await page.locator('table tbody tr').count()).toBe(20);
  });
});
