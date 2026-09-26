import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #702 — Leaflet.Draw edit/delete on the unit boundary map.
 *
 * On /units/{id}/map the draw toolbar's ویرایش (edit) and حذف (remove) tools did
 * nothing to the already-saved boundary; only the bottom "حذف مرز" button worked.
 *
 * Root cause: the toolbar is configured with `edit: { featureGroup: drawnItems }`
 * and Leaflet.Draw 1.0.4 only enables the edit/remove buttons when
 * `featureGroup.getLayers().length > 0` (see `_checkDisabled` in
 * public/js/leaflet/leaflet.draw.js). The saved boundary was added straight to the
 * map via `L.geoJSON(...).addTo(unitMap)` and never entered `drawnItems`, so the
 * buttons stayed disabled and the toolbar had nothing to operate on.
 *
 * The fix adds the individual polygon layers into `drawnItems` with
 * `existingLayer.eachLayer(l => drawnItems.addLayer(l))`.
 *
 * These tests assert the DOM state Leaflet.Draw itself keys off, so they fail
 * against the unfixed page and pass against the fixed one.
 */

const UNIT_MAP = /\/units\/\d+\/map/;

test.describe('units — boundary map draw toolbar (#702)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  /**
   * Opens a unit's map page that has a saved boundary. Falls back to discovering
   * one from the units table so the spec does not depend on a hardcoded id.
   */
  async function openUnitMapWithBoundary(page: import('@playwright/test').Page) {
    // Find a unit row whose map link exists, then open the first one that has a
    // boundary badge (مرز تعریف شده) rather than (بدون مرز).
    //
    // NOTE: do NOT await networkidle on a map page — Leaflet keeps fetching
    // tiles, so the network never goes idle and the wait times out. Wait for
    // the container plus the Leaflet toolbar instead.
    await page.goto('/units');
    await page.waitForLoadState('domcontentloaded');

    const candidateIds = await page.evaluate(() => {
      const ids = new Set<number>();
      document.querySelectorAll('a[href*="/map"]').forEach((a) => {
        const m = a.getAttribute('href')?.match(/\/units\/(\d+)\/map/);
        if (m) ids.add(Number(m[1]));
      });
      return Array.from(ids);
    });

    expect(candidateIds.length, 'expected at least one unit map link').toBeGreaterThan(0);

    for (const id of candidateIds) {
      await page.goto(`/units/${id}/map`);
      await page.locator('#unitMap').waitFor({ state: 'visible', timeout: 15000 });
      await page.locator('.leaflet-draw-edit-edit').waitFor({ state: 'visible', timeout: 15000 });

      const hasBoundary = await page.locator('.badge', { hasText: 'مرز تعریف شده' }).count();
      if (hasBoundary > 0) return id;
    }

    return null;
  }

  test('the draw toolbar edit/remove buttons are ENABLED when a boundary exists', async ({ page }) => {
    const unitId = await openUnitMapWithBoundary(page);

    test.skip(unitId === null, 'no seeded unit has a boundary in this environment');

    await expect(page.locator('#unitMap')).toBeVisible();

    // Leaflet.Draw marks a disabled mode button with `.leaflet-disabled`.
    // `_checkDisabled` only clears that class when featureGroup has layers.
    const editButton = page.locator('.leaflet-draw-edit-edit');
    const removeButton = page.locator('.leaflet-draw-edit-remove');

    await expect(editButton).toHaveCount(1);
    await expect(removeButton).toHaveCount(1);

    // THE REGRESSION ASSERTION (#702): before the fix both buttons carried
    // `.leaflet-disabled` because the saved polygon was never added to
    // `drawnItems`, so the toolbar was inert.
    await expect(editButton).not.toHaveClass(/leaflet-disabled/);
    await expect(removeButton).not.toHaveClass(/leaflet-disabled/);
  });

  test('the saved boundary polygon is a member of window._drawnItems', async ({ page }) => {
    const unitId = await openUnitMapWithBoundary(page);

    test.skip(unitId === null, 'no seeded unit has a boundary in this environment');

    // The fix adds each polygon from the L.GeoJSON group into drawnItems.
    // Before the fix this was 0 and updateGeojson() produced null, which is why
    // the toolbar delete could never reach the boundary.
    const info = await page.evaluate(() => {
      const w = window as unknown as {
        L?: { Polygon: new (...a: never[]) => unknown; GeoJSON: unknown };
        _drawnItems?: { getLayers(): unknown[] };
        _mapGeojson?: string | null;
      };
      const layers = w._drawnItems ? w._drawnItems.getLayers() : [];
      return {
        count: layers.length,
        // Leaflet ships minified in production, so constructor.name is a
        // single letter ("e"). Compare against the real L.Polygon prototype
        // instead of a hardcoded string.
        allPolygons: layers.every((l) => Boolean(w.L && l instanceof w.L.Polygon)),
        typeNames: layers.map((l) => (l as { constructor: { name: string } }).constructor.name),
        hasMapGeojson: typeof w._mapGeojson !== 'undefined',
      };
    });

    expect(info.count, 'saved boundary must be registered in the draw toolbar featureGroup').toBeGreaterThan(0);

    // #702: updateGeojson() iterates drawnItems and only serialises instances of
    // L.Polygon. If the saved boundary arrives as some other layer class it is
    // silently skipped, so saving drops the geometry to null.
    expect(
      info.allPolygons,
      `updateGeojson() only serialises L.Polygon layers — got constructors: ${info.typeNames.join(', ')}`,
    ).toBe(true);
  });

  test('clicking the toolbar edit button opens vertex handles on the boundary', async ({ page }) => {
    const unitId = await openUnitMapWithBoundary(page);

    test.skip(unitId === null, 'no seeded unit has a boundary in this environment');

    await page.locator('.leaflet-draw-edit-edit').click();
    await page.waitForTimeout(1200);

    // Leaflet.Edit.Polyline renders one .leaflet-editing-icon marker per vertex.
    expect(
      await page.locator('.leaflet-editing-icon').count(),
      'editing the saved boundary must produce vertex handles',
    ).toBeGreaterThan(2);

    // Cancel so the edit is not left open.
    const cancel = page.locator('.leaflet-draw-actions a', { hasText: /Cancel|انصراف/ }).first();
    if (await cancel.count()) await cancel.click();
  });

  test('toolbar remove empties _drawnItems so ذخیره routes to deleteBoundary', async ({ page }) => {
    const unitId = await openUnitMapWithBoundary(page);

    test.skip(unitId === null, 'no seeded unit has a boundary in this environment');

    // Remove mode: click the remove button, then click the polygon on the map.
    await page.locator('.leaflet-draw-edit-remove').click();
    await page.waitForTimeout(800);

    const polygonCount = await page.locator('#unitMap path.leaflet-interactive, #unitMap .leaflet-overlay-pane path').count();
    expect(polygonCount, 'expected the saved boundary to be drawn on the map').toBeGreaterThan(0);

    await page.locator('#unitMap .leaflet-overlay-pane path').first().click({ force: true });
    await page.waitForTimeout(1000);

    // Save the deletion.
    const save = page.locator('.leaflet-draw-actions a', { hasText: /Save|ذخیره/ }).first();
    if (await save.count()) await save.click();
    await page.waitForTimeout(1000);

    // saveMapBoundary() sees _mapGeojson === null and calls deleteBoundary().
    const after = await page.evaluate(() => {
      const w = window as unknown as { _drawnItems?: { getLayers(): unknown[] }; _mapGeojson?: string | null };
      return {
        layers: w._drawnItems ? w._drawnItems.getLayers().length : -1,
        geojson: w._mapGeojson ?? null,
      };
    });

    expect(after.layers, 'toolbar delete must remove the layer from drawnItems').toBe(0);
    expect(after.geojson, 'no geometry left means save routes to deleteBoundary').toBeNull();
  });

  test('the saved boundary is seeded into _mapGeojson on load', async ({ page }) => {
    const unitId = await openUnitMapWithBoundary(page);

    test.skip(unitId === null, 'no seeded unit has a boundary in this environment');

    // #702 regression: _mapGeojson used to stay undefined until the user drew
    // something. saveMapBoundary() treats a falsy _mapGeojson as "nothing to
    // save" and calls deleteBoundary() instead, so merely opening a unit that
    // already had a boundary and pressing ذخیره silently deleted it.
    const state = await page.evaluate(() => {
      const w = window as unknown as {
        _mapGeojson?: string | null;
        _drawnItems?: { getLayers(): unknown[] };
      };
      return {
        defined: typeof w._mapGeojson !== 'undefined',
        raw: (w._mapGeojson as string | null) ?? null,
        layerCount: w._drawnItems ? w._drawnItems.getLayers().length : -1,
      };
    });

    expect(state.layerCount, 'expected the boundary layer to be present').toBeGreaterThan(0);
    expect(state.defined, '_mapGeojson must be seeded, not left undefined').toBe(true);
    expect(state.raw, 'the seeded _mapGeojson must carry real geometry').toBeTruthy();

    // It must be valid GeoJSON whose ring holds real numbers, not the
    // [undefined, undefined, ...] that reading the nested MultiPolygon produced.
    const parsed = JSON.parse(state.raw as string) as {
      type: string;
      geometry: { type: string; coordinates: number[][][][] };
    };

    expect(parsed.type).toBe('Feature');
    expect(parsed.geometry.type).toBe('MultiPolygon');
    expect(parsed.geometry.coordinates.length).toBeGreaterThan(0);

    // MultiPolygon -> polygon[0] -> ring[0] -> [lng, lat] vertex
    const ring = parsed.geometry.coordinates[0][0];
    expect(ring.length, 'a real boundary ring has several vertices').toBeGreaterThan(2);

    for (const point of ring) {
      expect(Array.isArray(point), 'every ring entry must be a [lng, lat] pair').toBe(true);
      expect(typeof point[0]).toBe('number');
      expect(typeof point[1]).toBe('number');
    }

    // Closed ring: first and last vertex must match.
    const first = ring[0];
    const last = ring[ring.length - 1];
    expect(last[0]).toBe(first[0]);
    expect(last[1]).toBe(first[1]);
  });
});
