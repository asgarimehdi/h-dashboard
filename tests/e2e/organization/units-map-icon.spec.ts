import { test, expect, login } from '../shared/fixtures';

/**
 * Units list — map icon colour by boundary (owner request 2026-11-08).
 *
 * On /units the per-row map link (`a[href="/units/{id}/map"]`, `o-map` icon)
 * was `text-primary` for every row, so a unit with a saved boundary looked
 * identical to one without. After the fix:
 * - unit WITH a boundary: green (`text-success`), title «مرز تعریف شده»
 * - unit WITHOUT a boundary: grey (`text-base-content/50`), title «بدون مرز»
 *
 * The tooltip text matches the badge on the unit map page
 * (`units/map.blade.php`: «مرز تعریف شده» / «بدون مرز»); the colours are the
 * owner's choice (green/grey — "no map" is not an error, so never red).
 *
 * One sequential test (the file runs under `fullyParallel: true`, so two
 * tests would race on the shared boundary state): it asserts the grey state
 * first, draws and saves a boundary on unit A, then asserts A flips green
 * while a second boundary-less unit B stays grey.
 */

test.describe('units — map icon colour by boundary', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  async function collectUnitIds(page: import('@playwright/test').Page) {
    await page.goto('/units');
    await page.waitForLoadState('domcontentloaded');

    const ids = await page.evaluate(() => {
      const out = new Set<number>();
      document.querySelectorAll('a[href*="/map"]').forEach((a) => {
        const m = a.getAttribute('href')?.match(/\/units\/(\d+)\/map/);
        if (m) out.add(Number(m[1]));
      });
      return Array.from(out);
    });

    expect(ids.length, 'expected at least one unit map link').toBeGreaterThan(0);
    return ids;
  }

  async function hasNoBoundary(page: import('@playwright/test').Page, id: number) {
    // NOTE: do NOT await networkidle on a map page — Leaflet keeps fetching
    // tiles, so the network never goes idle and the wait times out.
    await page.goto(`/units/${id}/map`);
    await page.locator('#unitMap').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('.leaflet-draw-edit-edit').waitFor({ state: 'visible', timeout: 15000 });
    return (await page.locator('.badge', { hasText: 'بدون مرز' }).count()) > 0;
  }

  async function drawTriangleAndSave(page: import('@playwright/test').Page) {
    await page.locator('.leaflet-draw-draw-polygon').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('.leaflet-draw-draw-polygon').click();
    await page.waitForTimeout(500);

    const box = await page.locator('#unitMap').boundingBox();
    if (!box) throw new Error('unit map has no bounding box');
    const cx = box.x + box.width / 2;
    const cy = box.y + box.height / 2;
    // Leaflet.draw probe clicks need ~300ms gaps — back-to-back synthetic
    // clicks are swallowed and the polygon never closes.
    for (const [x, y] of [
      [cx - 60, cy - 40],
      [cx + 60, cy - 40],
      [cx + 60, cy + 40],
    ] as const) {
      await page.mouse.click(x, y);
      await page.waitForTimeout(300);
    }
    await page.mouse.click(cx - 60, cy - 40);

    await expect
      .poll(async () => page.evaluate(() => (window as unknown as { _mapGeojson?: string | null })._mapGeojson))
      .toBeTruthy();

    const update = page.waitForResponse(
      (r) => /\/livewire[^/]*\/update/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 15000 },
    );
    await page.locator('#saveMapBoundaryBtn').click();
    await update;

    await expect(page.locator('.badge', { hasText: 'مرز تعریف شده' })).toBeVisible({ timeout: 15000 });
  }

  test('map icon is grey without a boundary and green with one', async ({ page }) => {
    // Two map-page visits + a draw + a save: longer than the 30s default.
    test.setTimeout(120000);

    const ids = await collectUnitIds(page);

    // Find two boundary-less units (first page, id order — stable).
    const withoutBoundary: number[] = [];
    for (const id of ids.slice(0, 6)) {
      if (await hasNoBoundary(page, id)) withoutBoundary.push(id);
      if (withoutBoundary.length === 2) break;
    }
    expect(withoutBoundary.length, 'expected at least one unit without a boundary').toBeGreaterThan(0);

    const [unitA, unitB] = withoutBoundary;
    const linkA = page.locator(`a[href="/units/${unitA}/map"]`);
    const linkB = unitB ? page.locator(`a[href="/units/${unitB}/map"]`) : null;

    const expectGrey = async (link: import('@playwright/test').Locator) => {
      await expect(link).toHaveClass(/text-base-content\/50/);
      await expect(link).not.toHaveClass(/text-success/);
      await expect(link).not.toHaveClass(/text-primary/);
      await expect(link).toHaveAttribute('title', 'بدون مرز');
    };
    const expectGreen = async (link: import('@playwright/test').Locator) => {
      await expect(link).toHaveClass(/text-success/);
      await expect(link).not.toHaveClass(/text-base-content\/50/);
      await expect(link).not.toHaveClass(/text-primary/);
      await expect(link).toHaveAttribute('title', 'مرز تعریف شده');
    };

    // Pre-state: both boundary-less units show the grey icon.
    await page.goto('/units');
    await page.waitForLoadState('domcontentloaded');
    await expectGrey(linkA);
    if (linkB) await expectGrey(linkB);

    // Save a boundary on A, then re-check the list.
    await page.goto(`/units/${unitA}/map`);
    await page.locator('#unitMap').waitFor({ state: 'visible', timeout: 15000 });
    await drawTriangleAndSave(page);

    await page.goto('/units');
    await page.waitForLoadState('domcontentloaded');
    await expectGreen(linkA);
    // B untouched by the save — still grey.
    if (linkB) await expectGrey(linkB);
  });
});
