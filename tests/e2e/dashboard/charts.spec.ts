import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 011 — Dashboard charts
 * Probed DOM facts:
 * - Highcharts: #ticketTrendChart (areaspline) + #ticketStatusChart (pie)
 * - Renders .highcharts-root containers with .highcharts-point SVG nodes
 *   (35 total points across both charts when data present).
 * - Task progress card "وضعیت وظایف" with % bar; recent activity "آخرین فعالیت‌ها"
 *   with "مشاهده همه →" link to /activity-log.
 */

test.describe('dashboard charts', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2500); // Highcharts async init
  });

  test('receives trend and status charts render', async ({ page }) => {
    await expect(page.locator('#ticketTrendChart')).toBeVisible();
    await expect(page.locator('#ticketStatusChart')).toBeVisible();
    // Highcharts draws SVG containers.
    expect(await page.locator('.highcharts-root').count()).toBeGreaterThanOrEqual(2);
  });

  test('charts render SVG data points', async ({ page }) => {
    // Pie + areaspline both produce .highcharts-point nodes.
    expect(await page.locator('.highcharts-point').count()).toBeGreaterThan(0);
  });

  test('task progress bar renders', async ({ page }) => {
    await expect(page.locator('body')).toContainText('وضعیت وظایف');
    await expect(page.locator('body')).toContainText('%');
  });

  test('"مشاهده همه" links to activity log', async ({ page }) => {
    await expect(page.locator('body')).toContainText('آخرین فعالیت‌ها');
    const link = page.locator('a[href="/activity-log"]').first();
    await expect(link).toBeVisible();
  });
});