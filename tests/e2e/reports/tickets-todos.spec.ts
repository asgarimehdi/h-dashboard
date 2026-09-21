import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 009 — Reports tickets/todos
 * Probed DOM facts:
 * - /reports/todos → "گزارش وظایف", columns # | عنوان | واحد | شروع | پایان | وضعیت (50 rows)
 * - /reports/tickets → "گزارش تیکت‌ها" with report-type selector (تیکت‌ها/وظایف/پرسنل)
 *   and a unit selector — a multi-tab report page, not a single table.
 */

test.describe('reports todos', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/reports/todos');
    await page.waitForLoadState('networkidle');
  });

  test('todos report renders with columns', async ({ page }) => {
    await expect(page.locator('body')).toContainText('گزارش وظایف');
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['عنوان', 'واحد', 'شروع', 'پایان', 'وضعیت']) {
      expect(headers).toContain(col);
    }
  });
});

test.describe('reports tickets', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/reports/tickets');
    await page.waitForLoadState('networkidle');
  });

  test('tickets report renders with report-type selector', async ({ page }) => {
    await expect(page.locator('body')).toContainText('گزارش تیکت‌ها');
    await expect(page.locator('body')).toContainText('تیکت‌ها');
    await expect(page.locator('body')).toContainText('وظایف');
    await expect(page.locator('body')).toContainText('پرسنل');
  });
});