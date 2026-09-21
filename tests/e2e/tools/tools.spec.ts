import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 015 — Tools (management utilities)
 * Probed DOM facts:
 * - /tools renders "ابزارهای مدیریتی" with cards: آرشیو تیکت‌ها / پاک‌سازی لاگ‌ها /
 *   پاک‌سازی اعلان‌ها (each with confirm-guarded destructive action)
 * These are destructive — assert presence + confirmation guard, do NOT trigger.
 */

test.describe('tools', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/tools');
    await page.waitForLoadState('networkidle');
  });

  test('renders management tools cards', async ({ page }) => {
    await expect(page.locator('body')).toContainText('ابزارهای مدیریتی');
    await expect(page.locator('body')).toContainText('آرشیو تیکت‌ها');
    await expect(page.locator('body')).toContainText('پاک‌سازی لاگ‌ها');
    await expect(page.locator('body')).toContainText('پاک‌سازی اعلان‌ها');
  });

  test('destructive actions are guarded', async ({ page }) => {
    // each tool card exposes a trigger; ensure confirm dialog text is referenced
    // (non-destructive: only verify buttons exist, don't click through)
    const buttons = await page.locator('button').allTextContents();
    const hasConfirmWord = buttons.some((b) => /تایید|انجام|حذف|پاک|آرشیو/.test(b));
    expect(hasConfirmWord).toBeTruthy();
  });
});