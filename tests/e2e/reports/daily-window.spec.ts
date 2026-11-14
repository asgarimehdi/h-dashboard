import { test, expect, login, waitForLivewire, TEST_USER } from '../shared/fixtures';
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
  let emptyScopeToken: string;

  test.beforeAll(async () => {
    token = execSync(
      `php tests/e2e/create-token.php ${TEST_USER.nCode} "reports:read"`,
      { cwd: process.cwd(), encoding: 'utf-8' },
    ).trim();

    // A throwaway account whose unit is created after `migrate:fresh --seed`
    // owns no todos at all. That is the only scope where "a day with zero rows"
    // is a fact instead of a draw: TodoSeeder only writes days of the *current*
    // month, so from the 14th onward a 14-day window can be fully covered and
    // "some day is zero" turned into a calendar-dependent coin flip (#1485).
    // On an empty scope a plain GROUP BY returns no days at all, so a full
    // window of zeros is the decisive proof that the days are materialised.
    const nCode = `8${Date.now().toString().slice(-7)}${String(
      Math.floor(Math.random() * 100),
    ).padStart(2, '0')}`;
    execSync(
      `php tests/e2e/create-pwd-user.php ${nCode} ${TEST_USER.password} "E2E-empty-${nCode}"`,
      { cwd: process.cwd(), encoding: 'utf-8' },
    );
    emptyScopeToken = execSync(
      `php tests/e2e/create-token.php ${nCode} "reports:read"`,
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

  test('GET /api/reports/todos?days=14 returns the whole window, oldest first', async ({ request }) => {
    // An empty scope still has to come back as a materialised window of 14
    // days: a plain GROUP BY over zero rows returns no days at all.
    const empty = await request.get('/api/reports/todos?days=14', {
      headers: { Authorization: `Bearer ${emptyScopeToken}` },
    });

    expect(empty.ok()).toBeTruthy();
    const emptyBody = await empty.json();

    expect(emptyBody.by_day).toHaveLength(14);
    expect(emptyBody.by_day.every((d: any) => d.count === 0)).toBeTruthy();

    // The seeded scope keeps the same shape, ascending and oldest first.
    const response = await request.get('/api/reports/todos?days=14', {
      headers: { Authorization: `Bearer ${token}` },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // The window decides the shape, never the rows: 14 entries, one per day,
    // no duplicate and no gap.
    expect(body.by_day).toHaveLength(14);
    const days = body.by_day.map((d: any) => d.day);
    expect(new Set(days).size).toBe(14);
    expect(days).toEqual([...days].sort());
    expect(body.by_day.every((d: any) => typeof d.count === 'number')).toBeTruthy();

    // Deliberately NOT asserting a zero-count day on the seeded window:
    // `TodoSeeder` scatters 50 random todos over the current month's days
    // 1..28, so every day of a narrow window can legitimately have rows.
    // That randomness failed this test on unrelated branches (run 43564479894,
    // 2026-11-14) and the retries reuse the same seeded database, so it
    // reported `failed`, not `flaky`. The zero-fill contract is pinned on the
    // empty scope above and on a window that cannot be fully covered — see
    // the next test.
  });

  test('GET /api/reports/todos?days=365 keeps a zero for a day with no todos', async ({ request }) => {
    const response = await request.get('/api/reports/todos?days=365', {
      headers: { Authorization: `Bearer ${token}` },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // 365 is `DailySeries::MAX_DAYS`, so this also pins the cap. A year-long
    // window always reaches days before the seeded month, where no todo can
    // exist — those days must come back as `0`, not as a gap.
    expect(body.by_day).toHaveLength(365);
    expect(body.by_day.some((d: any) => d.count === 0)).toBeTruthy();
    expect(body.by_day.every((d: any) => typeof d.count === 'number')).toBeTruthy();
  });
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

  /**
   * Issue #866 — the picker pages charted an axis rebuilt as "N days ending
   * today", so every past range drew the wrong days and dropped the rows that
   * lived inside the range the user picked. This reads the axis labels the user
   * actually looks at.
   */
  test('reports page axis follows the picked past range', async ({ page }) => {
    await login(page);
    await page.goto('/reports/tickets');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    // A range that ended months ago. A rebuilt axis would have ended today, so
    // its last label would be today's date instead of the picked to-day.
    const from = '1403/10/01';
    const to = '1403/10/07';

    // The date inputs are `readonly` and live inside `wire:ignore`, so they are
    // driven by jalali-datepicker, which dispatches `jdp:change` — exactly what
    // the page's own listener binds to `$wire.set('dateFrom'|'dateTo', …)`.
    const setRange = async (inputId: string, value: string) => {
      await page.locator(`#${inputId}`).evaluate(
        (el, v) => {
          const input = el as HTMLInputElement;
          input.value = v;
          input.dispatchEvent(new CustomEvent('jdp:change', { detail: { value: v }, bubbles: true }));
        },
        value,
      );
    };

    const fromUpdate = waitForLivewire(page);
    await setRange('advanced_report_date_from', from);
    await fromUpdate;

    const toUpdate = waitForLivewire(page);
    await setRange('advanced_report_date_to', to);
    await toUpdate;

    // The axis is redrawn client-side after the response, so poll the chart
    // rather than sleeping for a fixed 2.5s.
    // from..to inclusive is 7 days, and they are the 7 days that were picked.
    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const chart = (window as any).Highcharts?.charts?.find(
              (c: any) => c?.renderTo?.id === 'trendChart',
            );
            return chart ? (chart.xAxis[0].categories ?? null) : null;
          }),
        { timeout: 10000 },
      )
      .toEqual([
        '1403/10/01',
        '1403/10/02',
        '1403/10/03',
        '1403/10/04',
        '1403/10/05',
        '1403/10/06',
        '1403/10/07',
      ]);
  });
});
