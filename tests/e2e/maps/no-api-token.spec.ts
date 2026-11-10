import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #1399 — the /map page must ship NO bearer credential in the DOM and
 * must still load every `/api/gis/*` layer from the web session cookie alone.
 *
 * The Pest suite already asserts the rendered HTML carries no
 * `data-api-token` / `wire:snapshot` token. This spec is the end-to-end proof
 * that removing the token did not break the in-page fetch flow: it drives the
 * real browser, where `credentials: 'include'` and (critically)
 * `EnsureFrontendRequestsAreStateful::fromFrontend()`'s Referer gate actually
 * run — neither is exercised by Laravel's test client.
 */

/** Matches a Sanctum plaintext token: `<id>|<40+ alphanumeric chars>`. */
const BEARER_SHAPE = /\b\d+\|[A-Za-z0-9]{40,}\b/;

/** No Leaflet `networkidle` wait: tiles keep fetching forever. */
async function waitForMap(page: import('@playwright/test').Page) {
  await page.waitForLoadState('load');
  await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForTimeout(1500);
}

/**
 * Toggle the disease layer on and drive one `disease-map` request.
 *
 * Mirrors `disease-layer.spec.ts`'s local helper (that file keeps its helpers
 * private to its describe scope, so there is nothing to import).
 */
async function activateDiseaseLayer(page: import('@playwright/test').Page) {
  await page.locator('#disease-layer-toggle').click({ force: true });
  await expect(page.locator('#disease-controls')).toBeVisible({ timeout: 15000 });

  await page.waitForFunction(
    (sel) => {
      const el = document.querySelector(sel) as HTMLSelectElement | null;
      return el != null && el.options.length > 1;
    },
    '#disease-select',
    { timeout: 15000 },
  );
}

/**
 * Zoom below `DiseaseMapService::ZOOM_SWITCH` so the legend is populated.
 *
 * At the default zoom of 10 the layer is in unit mode and the legend is
 * intentionally empty.
 */
async function assertLegendSwatch(page: import('@playwright/test').Page) {
  await page.evaluate(() => {
    const map = (window as any).Alpine?.store('map')?.get();
    if (map) map.setZoom(8);
  });
  await page.waitForFunction(
    () => {
      const map = (window as any).Alpine?.store('map')?.get();
      return map != null && map.getZoom() < 9;
    },
    undefined,
    { timeout: 15000 },
  );
  await page.waitForTimeout(1500);

  const swatch = page.locator('#disease-legend span').filter({
    has: page.locator('span.inline-block'),
  }).first();

  await expect(swatch).toBeVisible({ timeout: 15000 });

  const style = await swatch.locator('span.inline-block').first().getAttribute('style');
  expect(style, 'legend swatch must carry a non-empty background style').toBeTruthy();
}

test.describe('maps — no plaintext api token (#1399)', () => {
  test('the map page ships no token and every gis fetch uses the session cookie', async ({
    page,
  }) => {
    test.setTimeout(120_000);

    await login(page);

    // Collect every GIS request's headers for the "no Authorization" assertion.
    // Scoped to /api/gis/ on purpose: `zabbix-gauges.js` legitimately sends a
    // Bearer header to /api/zabbix/* from localStorage.
    const gisRequests: { url: string; authorization: string | undefined }[] = [];
    page.on('request', (request) => {
      const path = new URL(request.url()).pathname;
      if (!/\/api\/gis\//.test(path)) return;

      gisRequests.push({
        url: request.url(),
        authorization: request.headers()['authorization'],
      });
    });

    // Arm the waiter BEFORE the navigation: waitForResponse only observes
    // requests that start after it is called.
    const unitsResponse = page.waitForResponse(
      (response) => /\/api\/gis\/units/.test(new URL(response.url()).pathname),
      { timeout: 30000 },
    );

    await page.goto('/map');
    await waitForMap(page);

    expect(await unitsResponse).toBeTruthy();
    expect((await unitsResponse).status()).toBe(200);
    // The credential is gone from the SSR markup...
    const container = page.locator('#map-container');
    expect(await container.getAttribute('data-api-token')).toBeNull();

    // ...and from the Livewire snapshot copy (the browser evaluates this, so an
    // entity-encoded `&quot;mapToken&quot;` is decoded and caught too).
    const body = await page.evaluate(() => document.body.innerHTML);
    expect(body).not.toMatch(BEARER_SHAPE);
    expect(body).not.toContain('data-api-token');

    // Layers still render from the cookie.
    const unitMarkers = page.locator('.unit-marker');
    await expect(unitMarkers.first()).toBeVisible({ timeout: 15000 });
    expect(await unitMarkers.count()).toBeGreaterThan(10);

    // The disease layer — the other two cookie-authenticated fetch paths.
    const diseaseMapResponse = page.waitForResponse(
      (response) => /\/api\/gis\/disease-map/.test(new URL(response.url()).pathname),
      { timeout: 30000 },
    );
    await activateDiseaseLayer(page);
    expect((await diseaseMapResponse).status()).toBe(200);

    await assertLegendSwatch(page);

    expect(gisRequests.length).toBeGreaterThan(0);
    for (const request of gisRequests) {
      expect(
        request.authorization,
        `GIS request must authenticate by session cookie, not a bearer token: ${request.url}`,
      ).toBeUndefined();
    }
  });
});
