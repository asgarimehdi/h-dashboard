import { test, expect, login } from '../shared/fixtures';

/**
 * Plans 012 — Settings & Profile
 * Probed DOM facts:
 * - /settings: toggle checkboxes (اعلان ایمیلی/اعلان مرورگر/حالت فشرده) +
 *   "بروزرسانی خودکار" select (غیرفعال/۱۵ثانیه/۳۰ثانیه/۱دقیقه) + "ذخیره تنظیمات".
 * - /profile: renders "پروفایل من" with کاربر name/n_code/unit + "تغییر رمز عبور".
 * NOTE: toggling persists to the shared admin account — assert presence + toggles
 * exist, but do NOT flip them (would mutate the seeded account's settings).
 */

test.describe('settings', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/settings');
    await page.waitForLoadState('networkidle');
  });

  test('renders notification + dashboard toggles', async ({ page }) => {
    await expect(page.locator('body')).toContainText('اعلان ایمیلی');
    await expect(page.locator('body')).toContainText('اعلان مرورگر');
    await expect(page.locator('body')).toContainText('حالت فشرده');
    // 3 toggle checkboxes (email/browser/compact)
    expect(await page.locator('input[type="checkbox"].toggle').count()).toBe(3);
  });

  test('dashboard refresh select offers 4 options', async ({ page }) => {
    await expect(page.locator('body')).toContainText('بروزرسانی خودکار');
    const options = await page.locator('select.select-bordered').evaluate((s) =>
      [...s.options].map((o) => o.textContent!.trim()),
    );
    expect(options).toEqual(expect.arrayContaining(['غیرفعال', 'هر ۱۵ ثانیه', 'هر ۳۰ ثانیه', 'هر ۱ دقیقه']));
  });

  test('save button is present', async ({ page }) => {
    await expect(page.getByRole('button', { name: 'ذخیره تنظیمات' })).toBeVisible();
  });
});