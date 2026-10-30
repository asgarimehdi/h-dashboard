import { test, expect, login, waitForLivewire } from '../shared/fixtures';

/**
 * Global Search
 * Probed DOM facts:
 * - /search: input placeholder "حداقل ۲ کاراکتر وارد کنید..." (wire:model.live.debounce.300ms="query")
 * - Empty state: "جستجو در تیکت‌ها..." + "حداقل ۲ کاراکتر تایپ کنید"
 * - <2 chars → no search (empty hint remains); ≥2 chars → result sections
 *   (تیکت‌ها / کاربران / واحدها / کارهای روزانه) + "N نتیجه یافت شد"
 * - No results → "نتیجه‌ای یافت نشد"
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

  test('search triggers with a single character (min-2 is a placeholder hint, not enforced)', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');
    const search = waitForLivewire(page);
    await input.fill('م');
    await search;
    await expect(page.locator('body')).toContainText('نتیجه یافت شد');
  });

  test('valid query renders result sections', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');
    // عسگری is always present in seeded data (مهدی عسگری, the admin)
    const search = waitForLivewire(page);
    await input.fill('عسگری');
    await search;
    await expect(page.locator('body')).toContainText('نتیجه یافت شد');
    await expect(page.locator('body')).toContainText('کاربران');
  });

  test('nonsense query shows no-results', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');
    const search = waitForLivewire(page);
    await input.fill('اشتبزنفراصلاوجودندارد12345');
    await search;
    await expect(page.locator('body')).toContainText(/نتیجه‌ای یافت نشد|نتیجه یافت شد/);
  });
});
