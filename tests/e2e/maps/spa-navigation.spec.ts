import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #028 — Leaflet lifecycle on SPA navigation.
 *
 * /maps/point rendered its 783 markers on a direct load, but NONE when reached
 * by clicking the sidebar link (wire:navigate) from another map page. Measured
 * 0/6 runs failing, versus 783 markers on direct load.
 *
 * Root cause: the Leaflet instance lived on `window.map`, which is never
 * cleared, so the previous page's instance survived SPA navigation. Host pages
 * waited for "window.map exists" — a condition a detached instance satisfies —
 * bound their layers to that dying map, and then `initMap()` built a fresh map
 * and wiped the layers.
 *
 * These tests navigate by clicking the real sidebar link, which is the path
 * that failed. The pre-existing map specs all use `page.goto()` — the path that
 * always worked — so without this file the bug was invisible to CI.
 */

const MAP_PAGES = ['/maps/county', '/maps/unit', '/maps/route2'] as const;

/**
 * Wait for the map container without `networkidle`: Leaflet keeps fetching
 * tiles forever, so that wait always times out on a map page.
 */
async function waitForMap(page: import('@playwright/test').Page) {
  await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
  // Give the store time to hand back a live instance and let layers attach.
  await page.waitForTimeout(1500);
}

/** Navigate via the sidebar so wire:navigate runs (not a full page load). */
async function navigateByMenu(page: import('@playwright/test').Page, href: string) {
  const link = page.locator(`a[href="${href}"]`).first();
  await link.waitFor({ state: 'visible', timeout: 15000 });
  await link.click();
  await page.waitForURL(`**${href}`, { timeout: 15000 });
  await waitForMap(page);
}

test.describe('maps — SPA navigation lifecycle (#028)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  for (const from of MAP_PAGES) {
    test(`${from} → /maps/point renders point markers`, async ({ page }) => {
      await page.goto(from);
      await waitForMap(page);

      await navigateByMenu(page, '/maps/point');

      // THE REGRESSION ASSERTION: before the fix this count was 0.
      expect(
        await page.locator('#map .leaflet-marker-icon').count(),
        'markers must render when /maps/point is reached by SPA navigation',
      ).toBeGreaterThan(100);

      // The store must be bound to the container that is actually on screen.
      // Read Alpine.store('map') — NOT window.map: `id="map"` makes the browser
      // expose the element itself as window.map, so that global is meaningless.
      const bound = await page.evaluate(() => {
        const w = window as unknown as {
          Alpine?: { store: (name: string) => { get: () => { getContainer(): HTMLElement } | null } };
        };
        const instance = w.Alpine?.store('map')?.get() ?? null;
        const el = document.getElementById('map');
        return !!instance && instance.getContainer() === el;
      });
      expect(bound, 'the store instance must be bound to the live #map container').toBe(true);
    });
  }

  test('/maps/point → /maps/county → /maps/point round trip keeps markers', async ({ page }) => {
    await page.goto('/maps/point');
    await waitForMap(page);
    expect(
      await page.locator('#map .leaflet-marker-icon').count(),
      'direct load is the control case and must keep working',
    ).toBeGreaterThan(100);

    await navigateByMenu(page, '/maps/county');
    await navigateByMenu(page, '/maps/point');

    expect(
      await page.locator('#map .leaflet-marker-icon').count(),
      'a round trip must not lose the markers of the page returned to',
    ).toBeGreaterThan(100);
  });

  test('/dashboard → /maps/point renders markers (non-map origin)', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('domcontentloaded');

    await navigateByMenu(page, '/maps/point');

    expect(await page.locator('#map .leaflet-marker-icon').count()).toBeGreaterThan(100);
  });

  test('direct load still renders markers (no regression)', async ({ page }) => {
    await page.goto('/maps/point');
    await waitForMap(page);
    expect(await page.locator('.leaflet-marker-icon').count()).toBeGreaterThan(100);
  });
});