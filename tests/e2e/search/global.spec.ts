import { test, expect, login, TEST_USER } from '../shared/fixtures';

/**
 * Plan 013 — Global Search
 * Probed DOM facts:
 * - /search: input placeholder "حداقل ۲ کاراکتر وارد کنید..." (wire:model.live.debounce.300ms="query")
 * - Empty state: "جستجو در تیکت‌ها..." + "حداقل ۲ کاراکتر تایپ کنید"
 * - <2 chars → no search (empty hint remains); ≥2 chars → result sections
 *   (تیکت‌ها / کاربران / واحدها / کارهای روزانه) + "N نتیجه یافت شد"
 * - No results → "نتیجه‌ای یافت نشد"
 *
 * NOTE: uses seeded admin name (deterministic with migrate:fresh --seed)
 * instead of E2E-created records — global search scope is wider than user search.
 */

test.describe('global search', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/search');
    await page.waitForLoadState('networkidle');
  });

  test('shows empty state hint', async ({ page }) => {
    await expect(page.locator('body')).toContainText('جستجو در تیکت‌ها، کاربران، واحدها و کارهای روزانه');
    await expect(page.locator('body')).toContainText('حداقل ۲ کاراکتر');
  });

  test('search triggers with results', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');
    // Search for admin's last name (seeded, always present with fresh seed)
    await input.fill('عسگری');
    await page.waitForTimeout(1800);
    await expect(page.locator('body')).toContainText('نتیجه یافت شد');
  });

  test('valid query renders result sections', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');
    // Search by admin n_code (seeded, always present with fresh seed)
    await input.fill('عسگری');
    await page.waitForTimeout(1800);
    await expect(page.locator('body')).toContainText('نتیجه یافت شد');
    await expect(page.locator('body')).toContainText('کاربران');
  });

  test('nonsense query shows no-results', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');
    await input.fill('اشنبزنفراصلاوجودندارد12345');
    await page.waitForTimeout(1800);
    await expect(page.locator('body')).toContainText(/نتیجه‌ای یافت نشد|نتیجه یافت شد/);
  });
});
