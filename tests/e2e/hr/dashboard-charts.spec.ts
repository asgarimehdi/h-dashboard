import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #1499 — /hr-dashboard refreshed about once a second and its charts
 * flashed.
 *
 * Mechanism (confirmed against the locked Livewire vendor bundle): the
 * `morphed` hook in dashboard-charts.js called `renderHrCharts()`, which did
 * `component.call('chartPayload')`. Every Livewire method call renders HTML,
 * every rendered HTML effect morphs, and `morph2()` fires `morphed` with no
 * equality short-circuit — so the hook re-entered itself as fast as the server
 * answered. Each morph also wipes the chart SVG (the containers are empty in
 * the server HTML), and `renderHrBar()` rebuilds it: the flash.
 *
 * The fix makes the paint read a server-rendered inert block instead of the
 * server, the same shape as `#dashboard-chart-data` (#1352) and the #1042
 * reports' `data-chart`. The last two assertions separate that fix from
 * "delete the hook": a morph still wipes the SVG, so the repaint must happen —
 * with zero requests.
 */

/**
 * Livewire 4 mounts its update endpoint under a build-hashed prefix
 * (`POST livewire-46246a44/update`), so match the shape rather than a literal
 * path — `/livewire/update` and `/livewire-<hash>/update` both qualify.
 */
const LIVE_UPDATES = (urls: string[]) =>
  urls.filter((url) => /\/livewire[^/]*\/update$/.test(new URL(url).pathname));

const CHART_IDS = ['#hrChartByUnit', '#hrChartBySemat', '#hrChartByTahsil', '#hrChartByEstekhdam'];

test.describe('HR dashboard charts (#1499)', () => {
  test('no Livewire update after load, and charts survive a real morph', async ({ page }) => {
    await login(page);
    await page.goto('/hr-dashboard');
    await page.waitForLoadState('networkidle');

    // First paint done (Highcharts loads from @assets before the hook paints).
    for (const id of CHART_IDS) {
      await expect(page.locator(`${id} svg`)).toHaveCount(1);
    }

    const posts: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST') posts.push(req.url());
    });
    await page.waitForTimeout(3000);

    // Before the fix this window holds ~3 updates (one per round-trip).
    expect(LIVE_UPDATES(posts).length, 'the HR dashboard must not poll the server').toBe(0);
    for (const id of CHART_IDS) {
      await expect(page.locator(`${id} svg`)).toHaveCount(1);
    }
    expect(await page.evaluate(() => (window as any).Highcharts.charts.filter(Boolean).length)).toBe(4);

    // A real morph still repaints (morph wipes the SVG): the help modal commits
    // `showHelpModal` with `$set` (components/help/button.blade.php:12).
    await page.click('button[aria-label="نمایش راهنمای hr-dashboard"]');
    await page.waitForTimeout(1000);
    for (const id of CHART_IDS) {
      await expect(page.locator(`${id} svg`)).toHaveCount(1);
    }
    expect(await page.evaluate(() => (window as any).Highcharts.charts.filter(Boolean).length)).toBe(4);
  });
});
