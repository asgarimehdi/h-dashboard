import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 006 — Personnel import page
 * Probed DOM facts:
 * - /kargozini/persons/import renders "ورود اطلاعات پرسنل از فایل اکسل"
 * - file input present; "پیش‌نمایش و مقایسه" button; supported .xlsx/.xls/.csv
 */

test.describe('personnel import', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('import page loads with file upload control', async ({ page }) => {
    await page.goto('/kargozini/persons/import');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText('ورود اطلاعات پرسنل از فایل اکسل');
    await expect(page.locator('input[type="file"]')).toBeVisible();
    await expect(page.locator('body')).toContainText('پیش‌نمایش و مقایسه');
  });
});