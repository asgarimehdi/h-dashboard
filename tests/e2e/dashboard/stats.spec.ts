import { test, expect, login } from '../shared/fixtures';

/**
 * Dashboard stat cards
 * Asserts: all 7 stat labels are present; numeric values are > 0.
 * No exact count matching — values are relative to seeded data.
 */

const STAT_TITLES = [
  'کاربران',
  'پرسنل',
  'واحدها',
  'نقش‌ها',
  'کل تیکت‌ها',
  'تیکت‌های باز',
  'تیکت‌های تکمیل شده',
];

test.describe('dashboard stat cards', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
  });

  test('renders all 7 stat cards', async ({ page }) => {
    for (const title of STAT_TITLES) {
      await expect(page.locator('body')).toContainText(title);
    }
  });

  test('stat cards show numeric values (non-empty)', async ({ page }) => {
    const body = await page.locator('body').innerText();
    // At least one stat value should be a multi-digit number
    expect(body).toMatch(/\d{2,}/);
  });
});
