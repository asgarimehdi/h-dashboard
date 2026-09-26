import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #703 — when Zabbix is unreachable the Networks and Wireless pages must
 * say «دسترسی به سرور مقدور نمی باشد» instead of showing a raw HTTP code or
 * a silently blank chart.
 *
 * The API layer already returns 503 (`{'error': 'Service temporarily
 * unavailable'}`) from TrafficController and MultiLatestValueController. This
 * spec stubs those routes at the network layer, so it exercises the exact
 * client-side mapping the user asked for.
 *
 * Probed DOM facts:
 * - /it/networks renders one traffic chart per network (wire:key="traffic-…").
 * - /it/wireless renders the signal gauges (window.signalGauge).
 * - The friendly message is «دسترسی به سرور مقدور نمی باشد».
 */

const UNREACHABLE = 'دسترسی به سرور مقدور نمی باشد';

/** Route both Zabbix APIs to a 503, as the controllers do when Zabbix is down. */
async function stubZabbixDown(page: import('@playwright/test').Page) {
  await page.route('**/api/zabbix/traffic*', (route) =>
    route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Service temporarily unavailable' }),
    }),
  );
  await page.route('**/api/zabbix/multi-latest*', (route) =>
    route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Service temporarily unavailable' }),
    }),
  );
}

test.describe('Zabbix unreachable — friendly message (#703)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await stubZabbixDown(page);
  });

  test('the wireless gauges say the server is unreachable, not "خطای HTTP 503"', async ({ page }) => {
    await page.goto('/it/wireless');
    await expect(page.locator('body')).toContainText(UNREACHABLE, { timeout: 15000 });

    // the raw technical string must not be shown any more
    await expect(page.locator('body')).not.toContainText('خطای HTTP 503');
  });

  test('the networks charts say the server is unreachable instead of staying blank', async ({ page }) => {
    await page.goto('/it/networks');
    await expect(page.locator('body')).toContainText(UNREACHABLE, { timeout: 15000 });
  });
});

test.describe('Zabbix reachable — no false error (#703)', () => {
  test('a healthy response must not show the unreachable message', async ({ page }) => {
    await login(page);

    await page.route('**/api/zabbix/traffic*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          out: [[Date.now(), 1.5]],
          in: [[Date.now(), 2.5]],
        }),
      }),
    );
    await page.route('**/api/zabbix/multi-latest*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ '1': 55, '2': 2412, '3': 0.004 }),
      }),
    );

    await page.goto('/it/networks');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);

    await expect(page.locator('body')).not.toContainText(UNREACHABLE);
  });
});

test.describe('Zabbix error handling — non-503 stays technical (#703)', () => {
  test('a 400 must not be mislabelled as "server unreachable"', async ({ page }) => {
    await login(page);

    // 400 = a real bug (bad item ids). The friendly message must NOT appear,
    // otherwise genuine errors get hidden behind a vague Persian string.
    await page.route('**/api/zabbix/multi-latest*', (route) =>
      route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'item_ids نامعتبر است' }),
      }),
    );

    await page.goto('/it/wireless');
    await expect(page.locator('body')).toContainText('item_ids نامعتبر است', { timeout: 15000 });
    await expect(page.locator('body')).not.toContainText(UNREACHABLE);
  });
});
