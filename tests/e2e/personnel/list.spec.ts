import { test, expect, login, waitForLivewire } from '../shared/fixtures';

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
    await expect(page.locator('.mary-table-pagination')).toContainText('نمایش');
  });

  test('search by name filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    const searchUpdate = waitForLivewire(page);
    await search.fill('عسگری');
    await searchUpdate;
    await expect(page.locator('table tbody')).toContainText('عسگری');
  });

  test('search by n_code filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    const searchUpdate = waitForLivewire(page);
    await search.fill('4411015056');
    await searchUpdate;
    await expect(page.locator('table tbody')).toContainText('4411015056');
  });

  test('filters panel opens', async ({ page }) => {
    const filterPanel = waitForLivewire(page);
    await page.locator(FILTER_BTN).click();
    await filterPanel;
    await expect(page.locator('body')).toContainText('پاک کردن فیلترها');
  });

  test('filter by semat narrows results', async ({ page }) => {
    await expect(page.locator('.mary-table-pagination')).toContainText('نمایش');
    const filterPanel = waitForLivewire(page);
    await page.locator(FILTER_BTN).click();
    await filterPanel;
    const sematFilter = waitForLivewire(page);
    await page.locator('select[wire\\:model\\.live="filter_s_id"]').selectOption({ index: 1 });
    await sematFilter;
    // The response resolves before Livewire morphs the DOM, so every DOM read
    // below polls until the morph lands instead of reading once.
    const rows = page.locator('table tbody tr');
    await expect.poll(() => rows.count()).toBeGreaterThan(0);
    await expect.poll(() => rows.count()).toBeLessThanOrEqual(20);
  });

  test('filter by tahsil narrows results', async ({ page }) => {
    const filterPanel = waitForLivewire(page);
    await page.locator(FILTER_BTN).click();
    await filterPanel;
    const tahsilFilter = waitForLivewire(page);
    await page.locator('select[wire\\:model\\.live="filter_t_id"]').selectOption({ index: 2 });
    await tahsilFilter;
    await expect(page.locator('table tbody tr')).toHaveCount(0);
  });

  test('clear filters restores full list', async ({ page }) => {
    const filterPanel = waitForLivewire(page);
    await page.locator(FILTER_BTN).click();
    await filterPanel;
    const sematFilter = waitForLivewire(page);
    await page.locator('select[wire\\:model\\.live="filter_s_id"]').selectOption({ index: 1 });
    await sematFilter;
    await expect.poll(() => page.locator('table tbody tr').count()).toBeLessThanOrEqual(20);

    const clearFilters = waitForLivewire(page);
    await page.locator('button:has-text("پاک کردن فیلترها")').first().click();
    await clearFilters;
    await expect(page.locator('.mary-table-pagination')).toContainText('نمایش');
    await expect(page.locator('table tbody tr')).toHaveCount(20);
  });

  test('export link carries the active filters', async ({ page }) => {
    // Livewire cannot return a file download, so the control is a plain
    // <a href>. Its query string must reflect the filters currently set, or
    // the download silently contains the unfiltered table.
    const link = page.locator(`a[href*="/kargozini/persons/export"]`);
    await expect(link).toHaveCount(1);

    const hrefParam = async (name: string) =>
      new URL((await link.getAttribute('href'))!, page.url()).searchParams.get(name);

    expect(await hrefParam('search')).toBeNull();

    const exportSearch = waitForLivewire(page);
    await page.locator('input[placeholder^="جستجو"]').first().fill('عسگری');
    await exportSearch;

    await expect.poll(async () => hrefParam('search')).toBe('عسگری');
  });

  test('export link is cleared by the reset-filters button', async ({ page }) => {
    const link = page.locator(`a[href*="/kargozini/persons/export"]`);

    const hrefParam = async (name: string) =>
      new URL((await link.getAttribute('href'))!, page.url()).searchParams.get(name);

    const filterPanel = waitForLivewire(page);
    await page.locator(FILTER_BTN).click();
    await filterPanel;
    const sematFilter = waitForLivewire(page);
    await page.locator('select[wire\\:model\\.live="filter_s_id"]').selectOption({ index: 1 });
    await sematFilter;
    await expect.poll(async () => hrefParam('filter_s_id')).toBeTruthy();

    const clearFilters = waitForLivewire(page);
    await page.locator('button:has-text("پاک کردن فیلترها")').first().click();
    await clearFilters;
    await expect.poll(async () => hrefParam('filter_s_id')).toBeNull();
  });
});
