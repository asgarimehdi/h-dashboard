import { test, expect, login } from '../shared/fixtures';
import type { Page } from '@playwright/test';

/**
 * Issue #1404 — `renderGeoJSON` read `coordinates[0]`/`coordinates[1]` with no
 * validation before handing them to Leaflet.
 *
 * Leaflet 1.9.4's `LatLng` constructor throws on a non-finite value, and that
 * throw unwinds `features.forEach`, so every feature *after* the malformed one
 * in the same payload is never drawn — the layer is silently truncated. The
 * string shape (`"51,35"`) is worse: it plots a garbage marker at (1, 5) with
 * no error at all.
 *
 * The server cannot emit a malformed coordinate today (`(float)` casts +
 * `whereNotNull` in `GisController`), so the payload is intercepted at the
 * route instead of seeded in the database. That is the same precedent as
 * `route2-geocoding.spec.ts` and it is what makes the guard a defence in depth
 * against a future contract regression.
 */

const TILE_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

const point = (coordinates: unknown) => ({
  type: 'Feature',
  properties: { id: 1, name: 'x' },
  geometry: { type: 'Point', coordinates },
});

/** 9 features, 4 valid — malformed ones first and interleaved. */
const MIXED = {
  type: 'FeatureCollection',
  features: [
    point([]),
    point([51.1, 35.1]),
    point([null, null]),
    point([51.2, 35.2]),
    point('51.3,35.3'),
    point([51.3, 35.3]),
    point([[51, 35], [52, 36]]),
    point([51.4, 35.4]),
    point([[[51, 35], [52, 36], [51, 35]]]),
  ],
};

function collectErrors(page: Page): string[] {
  const errors: string[] = [];

  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));

  return errors;
}

async function stubTiles(page: Page): Promise<void> {
  await page.route(/tile\.openstreetmap\.org/, (route) =>
    route.fulfill({ status: 200, contentType: 'image/png', body: TILE_PNG }),
  );
}

/**
 * Wait for the map container and for the store to be bound to it, then give the
 * host's one-shot `onReady` callback time to attach its listeners. `networkidle`
 * is unusable here: Leaflet keeps requesting tiles.
 */
async function waitForMap(page: Page): Promise<void> {
  await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });

  await expect
    .poll(
      () =>
        page.evaluate(() => {
          const w = window as unknown as {
            Alpine?: { store: (name: string) => { get: () => { getContainer(): HTMLElement } | null } };
          };
          const map = w.Alpine?.store('map')?.get() ?? null;
          return !!map && map.getContainer() === document.querySelector('#map');
        }),
      { timeout: 15000, message: 'the store must hand out a map bound to the live #map container' },
    )
    .toBe(true);

  await page.waitForTimeout(750);
}

test('a malformed feature is skipped and does not abort the rest of the layer', async ({ page }) => {
  const errors = collectErrors(page);
  await stubTiles(page);
  await login(page);
  // Registered before `goto` so the dashboard's first units request is mocked.
  await page.route('**/api/gis/units**', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify(MIXED) }),
  );
  await page.goto('/map');
  await waitForMap(page);

  const markers = page.locator('#map svg path.unit-marker');
  await expect(markers).toHaveCount(4, { timeout: 15000 });

  const nanGeometry = await page.locator('#map svg path').evaluateAll(
    (paths) => paths.filter((p) => (p.getAttribute('d') ?? '').includes('NaN')).length,
  );
  expect(nanGeometry).toBe(0);
  expect(errors, errors.join('\n')).toEqual([]);
});

test('a string coordinate is skipped instead of plotting a garbage marker', async ({ page }) => {
  const errors = collectErrors(page);
  await stubTiles(page);
  await login(page);
  await page.route('**/api/gis/units**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ type: 'FeatureCollection', features: [point('51.4,35.7')] }),
    }),
  );
  await page.goto('/map');
  await waitForMap(page);

  // On beta this is one marker at (1, 5) and no console output at all.
  await expect(page.locator('#map svg path.unit-marker')).toHaveCount(0, { timeout: 15000 });
  expect(errors, errors.join('\n')).toEqual([]);
});

test('an all-valid payload renders 1:1 — the guard drops nothing valid', async ({ page }) => {
  const errors = collectErrors(page);
  await stubTiles(page);
  await login(page);
  await page.route('**/api/gis/units**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        type: 'FeatureCollection',
        features: [point([51.1, 35.1]), point([51.2, 35.2]), point([51.3, 35.3])],
      }),
    }),
  );
  await page.goto('/map');
  await waitForMap(page);

  await expect(page.locator('#map svg path.unit-marker')).toHaveCount(3, { timeout: 15000 });
  expect(errors, errors.join('\n')).toEqual([]);
});
