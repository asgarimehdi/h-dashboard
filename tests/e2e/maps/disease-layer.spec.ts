import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #1385 — CSP-safe Livewire build rejected two Alpine directive
 * expressions on the disease layer of /map:
 *
 *   L341  @change="$nextTick(() => loadDiseaseLayer(true))"
 *         → CSP parser: `Unexpected token: PUNCTUATION ")"`
 *   L364  :style="`background:${row.color}`"`
 *         → CSP parser: `Unexpected token: OPERATOR "`"`
 *
 * The fix (Plan 009) drops `$nextTick` (loadDiseaseLayer is plain JS and sets
 * `this.diseaseLoading = true` synchronously) and switches the style binding to
 * an object literal. These two tests are the regression guard: they drive the
 * real disease layer end-to-end on both the direct-load and the wire:navigate
 * paths, so a future regression that re-introduces a template-literal or
 * $nextTick-wrapped expression is caught before it ships.
 *
 * The spec copies `navigateByMenu` and `waitForMap` locally from
 * `spa-navigation.spec.ts` (N3 — no import: those helpers are private to that
 * file's describe scope).
 */

/**
 * Wait for the map container without `networkidle`: Leaflet keeps fetching
 * tiles forever, so that wait always times out on a map page.
 */
async function waitForMap(page: import('@playwright/test').Page) {
  // The map canvas is rendered by the nested `maps.map` Livewire component,
  // not directly by this page — give its render pass a moment before asserting.
  await page.waitForLoadState('load');
  await page.waitForTimeout(2000);
  await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
  // Give the store time to hand back a live instance and let layers attach.
  await page.waitForTimeout(1500);
}

/**
 * Navigate via the sidebar so wire:navigate runs (not a full page load).
 *
 * The maps links live inside a MaryUI <x-menu-sub>, which renders as a
 * <details> collapsed unless a child is already active. A collapsed <details>
 * still gives its children a non-empty box in Chromium, so isVisible() alone
 * is not a reliable "is it open" test — and at Playwright's 1280x720 viewport
 * the link can sit below the sidebar's scroll fold. So: open the submenu first
 * when needed, then scroll it into view before clicking.
 */
async function navigateByMenu(page: import('@playwright/test').Page, href: string) {
  const link = page.locator(`a[href="${href}"]`).first();

  // Open the submenu when it is collapsed. The toggle is the <summary> of the
  // same <details> that contains the link.
  const collapsed = await link.evaluate((el: Element) => {
    const details = el.closest('details');
    return !!details && !details.open;
  });
  if (collapsed) {
    await link
      .locator('xpath=ancestor::details/summary')
      .click({ force: true });
  }

  await link.scrollIntoViewIfNeeded();
  await link.click();
  await page.waitForURL(`**${href}`, { timeout: 15000 });
  // NOTE: we no longer call waitForMap() here — some targets (e.g. /dashboard)
  // have no map. Call waitForMap() explicitly in tests when the destination
  // is a map page.
}

/**
 * Toggle the disease layer on and drive a disease-map request.
 *
 * Returns the id of the disease selected so callers can assert on it.
 */
async function activateDiseaseLayer(page: import('@playwright/test').Page): Promise<number> {
  await page.locator('#disease-layer-toggle').click({ force: true });
  await expect(page.locator('#disease-controls')).toBeVisible({ timeout: 15000 });

  // The catalogue fetches lazily; wait for the selector to gain options.
  // DiseaseSeeder seeds 5 diseases + 1 placeholder = 6, but assert on
  // "more than the placeholder" rather than a fixed count so the spec
  // survives catalogue additions/removals.
  await page.waitForFunction(
    (sel) => {
      const el = document.querySelector(sel) as HTMLSelectElement | null;
      return el != null && el.options.length > 1;
    },
    '#disease-select',
    { timeout: 15000 },
  );

  // Arm the waiter BEFORE changing the disease: `page.waitForResponse` only
  // observes requests that start after it is called.
  const mapRequest = page.waitForResponse(
    (response) => /\/api\/gis\/disease-map/.test(new URL(response.url()).pathname),
    { timeout: 15000 },
  );

  // The catalogue auto-selects the first disease on load, so the default
  // already fired a request. Switch to a second disease to exercise the
  // `@change="loadDiseaseLayer(true)"` directive itself.
  await page.locator('#disease-select').selectOption({ index: 1 });
  await mapRequest;

  return Number(await page.locator('#disease-select').inputValue());
}

/**
 * Zoom out past `DiseaseMapService::ZOOM_SWITCH` (9) so the layer renders in
 * region mode, where `buildDiseaseLegend` populates `#disease-legend`.
 *
 * At the default zoom of 10 the layer is in unit mode and the legend is
 * intentionally empty (600 base-unit markers instead) — asserting on it
 * there would be a false negative.
 */
async function zoomOutToRegion(page: import('@playwright/test').Page) {
  // Zoom out past the region/unit switch so `buildDiseaseLegend` runs.
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
}

/**
 * Assert the legend swatch rendered with a non-empty background color.
 *
 * The e2e database has no disease_cases seeded, so every region renders at
 * rate=null and the legend collapses to the single no-data row
 * (`DISEASE_NO_DATA_COLOR = '#9ca3af'`). The assertion therefore checks the
 * style is present and non-empty, not a specific hue — a hue assertion would
 * couple the spec to the seeded data.
 */
async function assertLegendSwatch(page: import('@playwright/test').Page) {
  await zoomOutToRegion(page);
  // A zoom change fires `moveend` → `loadDiseaseLayer`, so give the
  // region-mode render a moment before looking for the legend.
  await page.waitForTimeout(1500);

  const swatch = page.locator('#disease-legend span').filter({
    has: page.locator('span.inline-block'),
  }).first();

  await expect(swatch).toBeVisible({ timeout: 15000 });

  const style = await swatch.locator('span.inline-block').first().getAttribute('style');
  expect(style, 'legend swatch must carry a non-empty background style').toBeTruthy();
  expect(style, 'legend swatch background style must name background').toContain('background');
}

test.describe('maps — disease layer (#1385)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('fresh page.goto(/map) renders the disease layer and legend', async ({ page }) => {
    await page.goto('/map');
    await waitForMap(page);

    const diseaseId = await activateDiseaseLayer(page);
    expect(diseaseId, 'a disease must be selected after the toggle').toBeGreaterThan(0);

    await assertLegendSwatch(page);
  });

  test('wire:navigate from the sidebar renders the disease layer', async ({ page }) => {
    await page.goto('/map');
    await waitForMap(page);
    await activateDiseaseLayer(page);

    // Leave /map by SPA navigation, then return via the real sidebar link —
    // the path that re-runs the component's Alpine init against a fresh map.
    await navigateByMenu(page, '/dashboard');
    await navigateByMenu(page, '/map');
    await waitForMap(page);

    const diseaseId = await activateDiseaseLayer(page);
    expect(diseaseId, 'a disease must be selected after the toggle').toBeGreaterThan(0);

    await assertLegendSwatch(page);
  });
});