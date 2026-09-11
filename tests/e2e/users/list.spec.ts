import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 004 — Users list (list-only; CRUD blocked by BUG-001/002, see crud.spec.ts)
 *
 * Probed DOM facts (not guessed):
 * - Columns: # | نام | کد ملی | واحد اصلی | نقش‌ها | وضعیت (+ hidden expand/actions cells)
 * - Search input: `input[placeholder^="جستجو"]` (placeholder is "جستجو... " with trailing space)
 * - Status filter: `select.select-bordered` (options all/active/inactive)
 * - Page size: `select.select-sm` (options 10/20/50/100)
 * - Pagination: `.mary-table-pagination` with "قبلی"/"بعدی" buttons + "نمایش X تا Y از 317 نتیجه"
 * - Row expand: click the chevron `svg` in the first `td` of a row → "دسترسی‌ها برای" panel
 */

test.describe('users list', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/users');
    await page.waitForLoadState('networkidle');
  });

  test('list loads with expected columns', async ({ page }) => {
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['#', 'نام', 'کد ملی', 'واحد اصلی', 'نقش‌ها', 'وضعیت']) {
      expect(headers).toContain(col);
    }
  });

  test('shows rows with data', async ({ page }) => {
    const rows = await page.locator('table tbody tr').count();
    expect(rows).toBeGreaterThan(0);
    // 317 users in total (excludes self), paginated.
    await expect(page.locator('.mary-table-pagination')).toContainText('317');
  });

  test('search by name filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    await search.fill('هادیلو');
    await page.waitForTimeout(1800); // debounce
    await expect(page.locator('table tbody')).toContainText('مهدی هادیلو');
  });

  test('search by n_code filters the list', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    await search.fill('0023548258');
    await page.waitForTimeout(1800);
    await expect(page.locator('table tbody')).toContainText('0023548258');
  });

  test('status filter switches active/inactive', async ({ page }) => {
    const select = page.locator('select.select-bordered');
    await select.selectOption('inactive');
    await page.waitForTimeout(1000);
    await expect(select).toHaveValue('inactive');

    await select.selectOption('active');
    await page.waitForTimeout(1000);
    await expect(select).toHaveValue('active');
  });

  test('page size select reloads table', async ({ page }) => {
    const perPageSelect = page.locator('select.select-sm');
    await expect(perPageSelect).toBeVisible();
    await perPageSelect.selectOption('10');
    await page.waitForTimeout(1000);
    await expect(page.locator('table').first()).toBeVisible();
    // 10/page → 32 pages for 317 users (> the 16 pages at 20/page)
    await expect(page.locator('.mary-table-pagination')).toContainText('32');
  });

  test('pagination navigates to the next page', async ({ page }) => {
    await expect(page.locator('.mary-table-pagination')).toContainText('نمایش 1 تا 20');
    await page.getByRole('button', { name: 'بعدی' }).click();
    await page.waitForTimeout(1000);
    await expect(page.locator('.mary-table-pagination')).toContainText('نمایش 21 تا 40');
  });

  test('expand row reveals permissions', async ({ page }) => {
    const chevron = page.locator('table tbody tr').first().locator('td').first().locator('svg');
    await chevron.click();
    await page.waitForTimeout(1000);
    await expect(page.locator('text=دسترسی‌ها برای').first()).toBeVisible();
  });
});