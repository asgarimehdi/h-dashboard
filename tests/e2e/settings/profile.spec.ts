import { test, expect, login } from '../shared/fixtures';

/**
 * Plans 012 — Profile (split into its own file per plan note)
 * Probed DOM facts: /profile renders "پروفایل من" + name/n_code/unit + "تغییر رمز عبور".
 */

test.describe('profile', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/profile');
    await page.waitForLoadState('networkidle');
  });

  test('profile renders user details', async ({ page }) => {
    await expect(page.locator('body')).toContainText('پروفایل من');
    await expect(page.locator('body')).toContainText('کد ملی');
    await expect(page.locator('body')).toContainText('تغییر رمز عبور');
  });
});