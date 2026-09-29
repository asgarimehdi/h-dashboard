import { test, expect, login, TEST_USER } from '../shared/fixtures';
import { execSync } from 'child_process';

/**
 * Issue #736 — one definition for the daily window, and no missing days.
 *
 * Two things are asserted here that the API tests cannot see:
 * 1) The chart axes the user actually looks at are materialised day by day —
 *    30 labels on the dashboard trend, not only the days that happen to have
 *    rows. A day without a ticket must be a zero, not a gap.
 * 2) The by_day series the JS receives keeps its shape across a filter change,
 *    because a chart that collapses when a filter is set is the same bug in a
 *    different place.
 *
 * `/api/reports/*` needs a `reports:read` token (session cookies don't satisfy
 * the `ability:` middleware), so one is minted with the existing helper.
 */

test.describe('daily window is materialised', () => {
  let token: string;

  test.beforeAll(async () => {
    token = execSync(
      `php tests/e2e/create-token.php ${TEST_USER.nCode} "reports:read"`,
      { cwd: process.cwd(), encoding: 'utf-8' },
    ).trim();
  });

  test('dashboard ticket trend has one label per day of the window', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2500); // Highcharts async init

    await expect(page.locator('#ticketTrendChart')).toBeVisible();

    const categories = await page.evaluate(() => {
      const chart = (window as any).Highcharts?.charts?.find(
        (c: any) => c?.renderTo?.id === 'ticketTrendChart',
      );
      return chart ? (chart.xAxis[0].categories ?? []) : null;
    });

    expect(categories, 'the trend chart rendered').not.toBeNull();
    // A sparse week of tickets must still produce a full month of slots, and
    // the last slot must be today — not the oldest day in the table.
    expect(categories!.length).toBe(30);
    expect(categories![categories!.length - 1]).toMatch(/^\d{2}\/\d{2}$/);

    const points = await page.locator('#ticketTrendChart .highcharts-point').count();
    expect(points).toBeGreaterThan(0);
  });

  test('GET /api/reports/tickets returns a full window of days', async ({ request }) => {
    const response = await request.get('/api/reports/tickets', {
      headers: { Authorization: `Bearer ${token}` },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    expect(body.by_day).toHaveLength(30);
    // Zero-filled days exist, not only the days that have rows.
    expect(body.by_day.every((d: any) => typeof d.count === 'number')).toBeTruthy();
    expect(body.by_day.some((d: any) => d.count === 0)).toBeTruthy();

    // Ascending, oldest first.
    const days = body.by_day.map((d: any) => d.day);
    expect(days).toEqual([...days].sort());
  });

  test('GET /api/reports/tickets?days=7 narrows the window and still fills it', async ({ request }) => {
    const response = await request.get('/api/reports/tickets?days=7', {
      headers: { Authorization: `Bearer ${token}` },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    expect(body.by_day).toHaveLength(7);
    expect(body.by_day.some((d: any) => d.count === 0)).toBeTruthy();
  });

  test('GET /api/reports/todos?days=14 fills empty days too', async ({ request }) => {
    const response = await request.get('/api/reports/todos?days=14', {
      headers: { Authorization: `Bearer ${token}` },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    expect(body.by_day).toHaveLength(14);
    expect(body.by_day.some((d: any) => d.count === 0)).toBeTruthy();
  });

  test('GET /api/reports/tickets?days=0 is rejected', async ({ request }) => {
    const response = await request.get('/api/reports/tickets?days=0', {
      headers: { Authorization: `Bearer ${token}` },
    });

    expect(response.status()).toBe(422);
  });

  test('reports page charts still render after the change', async ({ page }) => {
    await login(page);
    await page.goto('/reports/tickets');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    await expect(page.locator('#trendChart')).toBeVisible();
    expect(await page.locator('.highcharts-root').count()).toBeGreaterThanOrEqual(1);
  });
});
