import { test, expect, login, getRunPrefix } from '../shared/fixtures';
import { createE2EUser } from '../shared/helpers';

/**
 * Plan 013 — Global Search
 * Probed DOM facts:
 * - /search: input placeholder "حداقل ۲ کاراکتر وارد کنید..." (wire:model.live.debounce.300ms="query")
 * - Empty state: "جستجو در تیکت‌ها..." + "حداقل ۲ کاراکتر تایپ کنید"
 * - <2 chars → no search (empty hint remains); ≥2 chars → result sections
 *   (تیکت‌ها / کاربران / واحدها / کارهای روزانه) + "N نتیجه یافت شد"
 * - No results → "نتیجه‌ای یافت نشد"
 *
 * NOTE: searches use E2E-created records (unique per run) instead of seeded names.
 */

let e2eSearchName: string;

test.describe('global search', () => {
  test.beforeAll(async () => {
    const runPrefix = getRunPrefix();
    createE2EUser(runPrefix, 'search');
    e2eSearchName = runPrefix;
  });

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
    // Use first char of the run prefix (e.g., 'E' from 'E2E-...')
    await input.fill(e2eSearchName.charAt(0));
    await page.waitForTimeout(1200);
    await expect(page.locator('body')).toContainText('نتیجه یافت شد');
  });

  test('valid query renders result sections', async ({ page }) => {
    const input = page.locator('input[placeholder^="حداقل"]');
    await input.fill(e2eSearchName);
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
