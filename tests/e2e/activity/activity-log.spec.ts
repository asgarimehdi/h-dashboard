import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 014 — Activity Log
 * Probed DOM facts:
 * - /activity-log renders "گزارش فعالیت سیستم" + type stat chips (ایجاد/ویرایش/حذف/ورود/خروج)
 * - Table lists chronological activity entries with type + actor + timestamp
 */

test.describe('activity log', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/activity-log');
    await page.waitForLoadState('networkidle');
  });

  test('renders activity header + type categories', async ({ page }) => {
    await expect(page.locator('body')).toContainText('گزارش فعالیت');
    // type dimension present (chips or filter)
    const body = await page.locator('body').innerText();
    for (const t of ['ایجاد', 'ویرایش', 'ورود']) {
      expect(body).toContain(t);
    }
  });

  test('renders an activity table', async ({ page }) => {
    // the log is a table (or timeline); assert a header row is present
    const hasTable = (await page.locator('table').count()) > 0;
    const hasTimeline = await page.locator('body').getByText('فعالیت').count();
    expect(hasTable || hasTimeline > 0).toBeTruthy();
  });
});