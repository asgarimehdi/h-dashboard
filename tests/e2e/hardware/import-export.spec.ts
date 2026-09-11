import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 008 — Hardware import/export
 * Probed DOM facts:
 * - /hardware/import renders "ورود اطلاعات شناسنامه سخت‌افزار از فایل اکسل"
 *   with file input; supported .xlsx/.xls/.csv; match on pc_name or MAC.
 * - Export: toolbar "خروجی اکسل" → wire:click=exportExcel → triggers download event.
 */

test.describe('hardware import/export', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('import page loads with file upload control', async ({ page }) => {
    await page.goto('/hardware/import');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText('ورود اطلاعات شناسنامه سخت‌افزار از فایل اکسل');
    await expect(page.locator('input[type="file"]')).toBeVisible();
  });

  test('export button is present and enabled', async ({ page }) => {
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
    const exportBtn = page.getByRole('button', { name: 'خروجی اکسل' });
    await expect(exportBtn).toBeVisible();
    await expect(exportBtn).toBeEnabled();
  });

  test('export downloads real xlsx', async ({ page }) => {
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 15000 }),
      page.getByRole('button', { name: 'خروجی اکسل' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/\.xlsx?$/i);
    await expect.poll(async () => download.path(), { timeout: 15000 }).not.toBeNull();
  });
});
