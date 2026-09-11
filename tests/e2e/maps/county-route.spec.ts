import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 010 — GIS Maps: county polygons
 * Probed DOM facts:
 * - /maps/county renders SVG boundary polygons (8 counties), no marker icons.
 * - /maps/route renders a polyline (2 marker icons at endpoints).
 */

test.describe('maps — county boundaries', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/maps/county');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
  });

  test('county map renders SVG boundary polygons', async ({ page }) => {
    await expect(page.locator('.leaflet-container').first()).toBeVisible();
    // Boundary polygons are rendered as SVG paths inside the leaflet overlay pane.
    expect(await page.locator('svg path').count()).toBeGreaterThan(0);
  });
});

test.describe('maps — route', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/maps/route');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
  });

  test('route map renders endpoints + polyline', async ({ page }) => {
    await expect(page.locator('.leaflet-container').first()).toBeVisible();
    // route has two marker icons (start/end) and a polyline drawn as SVG path.
    expect(await page.locator('.leaflet-marker-icon').count()).toBeGreaterThanOrEqual(2);
    expect(await page.locator('svg path').count()).toBeGreaterThan(0);
  });
});