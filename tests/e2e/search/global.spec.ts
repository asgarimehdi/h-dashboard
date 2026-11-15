import { test, expect, login, waitForLivewire } from '../shared/fixtures';

/**
 * Global Search
 * Probed DOM facts:
 * - /search: input placeholder "حداقل ۲ کاراکتر وارد کنید..." (wire:model.live.debounce.300ms="query")
 * - Empty state: "جستجو در تیکت‌ها..." + "حداقل ۲ کاراکتر تایپ کنید"
 * - <2 CHARACTERS → no search (empty hint remains); ≥2 characters → result
 *   sections (تیکت‌ها / کاربران / واحدها / کارهای روزانه) + "N نتیجه یافت شد"
 *   The floor counts characters, not bytes: one Persian letter is 2 bytes
 *   but 1 character (issue #1483).
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

  test('single character does not search, two characters do (the guard counts characters, not bytes)', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');

    // One Persian letter is 2 bytes but 1 character — the byte-counting guard
    // used to let this through (issue #1483).
    const singleChar = waitForLivewire(page);
    await input.fill('م');
    await singleChar;
    await expect(page.locator('body')).toContainText('جستجو در تیکت‌ها، کاربران، واحدها و کارهای روزانه');
    await expect(page.locator('body')).not.toContainText('نتیجه یافت شد');

    // عسگری is always present in seeded data (مهدی عسگری, the admin), so this
    // two-character query must still search.
    const twoChars = waitForLivewire(page);
    await input.fill('عس');
    await twoChars;
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
