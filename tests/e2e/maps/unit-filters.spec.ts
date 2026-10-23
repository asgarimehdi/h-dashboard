import { test, expect, login } from '../shared/fixtures';
import type { Page } from '@playwright/test';

/**
 * Issue #1221 — /maps/unit lost every geometry after the first filter click.
 *
 * The host page binds its layers once, in `@script`/`onReady`, and the closures
 * keep the Leaflet instance they were handed. A structural morph (the county
 * tick inserts/removes the center/sub-type blocks and the unit list) used to
 * replace the `<livewire:maps.map>` subtree with a fresh `wire:id`, so Alpine
 * ran destroy/init on the child, the store's identity check failed, the old
 * instance was `remove()`d and a new one was built — while the host closures
 * stayed bound to the dead map. Every `addTo` then threw `appendChild`,
 * followed by a flood of `reading 'min'`.
 *
 * The fix is a stable `wire:key` on the host tag (unit-page-map and friends).
 * These specs pin the three observable consequences of that key:
 *
 *   1. the SAME #map element and the SAME wire:id survive every filter click,
 *   2. the store keeps handing out the SAME instance (no re-mount),
 *   3. geometry keeps rendering and the console stays clean.
 *
 * A separate section re-checks the #028 contract on `wire:navigate`, because
 * keeping the element alive on re-render must not leak it across pages.
 */

/**
 * Serve tiles from memory. The browser would otherwise log "Failed to load
 * resource" for every tile it cannot reach, which would drown the actual
 * assertion: Leaflet script errors must stay at zero.
 */
const TILE_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

const MAP_ELEMENT = '__issue1221MapElement';
const MAP_INSTANCE = '__issue1221MapInstance';

type Snapshot = {
  elementIsOriginal: boolean;
  wireId: string | null;
  containers: number;
  paths: number;
  instanceIsOriginal: boolean;
  boundToLiveElement: boolean;
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

/** Remember the element and the instance the page bound its closures to. */
async function mark(page: Page): Promise<void> {
  await page.evaluate(
    ([elementKey, instanceKey]) => {
      const w = window as unknown as Record<string, unknown> & {
        Alpine?: { store: (name: string) => { get: () => unknown } };
      };
      w[elementKey] = document.querySelector('#map');
      w[instanceKey] = w.Alpine?.store('map')?.get() ?? null;
    },
    [MAP_ELEMENT, MAP_INSTANCE] as const,
  );
}

function readSnapshot(page: Page): Promise<Snapshot> {
  return page.evaluate(
    ([elementKey, instanceKey]) => {
      const w = window as unknown as Record<string, unknown> & {
        Alpine?: { store: (name: string) => { get: () => { getContainer(): HTMLElement } | null } };
      };
      const element = document.querySelector('#map');
      const holder = element?.closest('[wire\\:id]');
      const map = w.Alpine?.store('map')?.get() ?? null;

      return {
        elementIsOriginal: !!element && element === w[elementKey],
        wireId: holder?.getAttribute('wire:id') ?? null,
        containers: document.querySelectorAll('.leaflet-container').length,
        paths: document.querySelectorAll('#map svg path').length,
        instanceIsOriginal: !!map && map === w[instanceKey],
        boundToLiveElement:
          !!map && map.getContainer() === element && !!element && element.isConnected,
      };
    },
    [MAP_ELEMENT, MAP_INSTANCE] as const,
  );
}

/**
 * THE REGRESSION ASSERTIONS. Before the fix, the county tick already failed all
 * of `elementIsOriginal`, `instanceIsOriginal` and `wireId` — the map subtree
 * was re-mounted and the old instance `remove()`d.
 */
async function expectMapSurvivedMorph(
  page: Page,
  wireId: string | null,
  expectations: { paths?: 'some' | 'none' } = {},
): Promise<void> {
  const snapshot = await readSnapshot(page);

  expect(snapshot.elementIsOriginal, '#map must be the same DOM element after a filter interaction').toBe(true);
  expect(snapshot.wireId, 'the map component must keep its wire:id (no re-mount)').toBe(wireId);
  expect(snapshot.containers, 'exactly one .leaflet-container must exist').toBe(1);
  expect(snapshot.instanceIsOriginal, 'the store must keep handing out the same Leaflet instance').toBe(true);
  expect(snapshot.boundToLiveElement, 'the store instance must stay bound to the live #map container').toBe(true);

  if (expectations.paths === 'some') {
    expect(snapshot.paths, 'geometry must be rendered on the map').toBeGreaterThan(0);
  }
  if (expectations.paths === 'none') {
    expect(snapshot.paths, 'clearing a filter must clear its geometry').toBe(0);
  }
}

/**
 * Open the sidebar submenu when collapsed, click the link, and wait for the
 * target page's map. Mirrors tests/e2e/maps/spa-navigation.spec.ts: the maps
 * links live in a MaryUI <x-menu-sub> that renders as a collapsed <details>,
 * where isVisible() is not a reliable "is it open" signal.
 */
async function navigateByMenu(page: Page, href: string): Promise<void> {
  const link = page.locator(`a[href="${href}"]`).first();

  const collapsed = await link.evaluate((el: Element) => {
    const details = el.closest('details');
    return !!details && !details.open;
  });
  if (collapsed) {
    await link.locator('xpath=ancestor::details/summary').click({ force: true });
  }

  await link.scrollIntoViewIfNeeded();
  await link.click();
  await page.waitForURL(`**${href}`, { timeout: 15000 });
  await waitForMap(page);
}

test.describe('maps/unit — map survives filter re-renders (#1221)', () => {
  test('county and center filters keep one map element, one wire:id and one instance', async ({ page }) => {
    const errors = collectErrors(page);
    await stubTiles(page);
    await login(page);

    await page.goto('/maps/unit');
    await waitForMap(page);
    await mark(page);

    const { wireId } = await readSnapshot(page);
    expect(wireId, '#map must sit inside a Livewire component with a wire:id').toBeTruthy();

    const counties = page.locator('input[wire\\:model\\.live="selectedRegions"]');
    const centers = page.locator('input[wire\\:model\\.live="selectedCenterTypes"]');
    const unitRows = page.locator('#unitMenu [wire\\:key^="unit-"]');
    const paths = page.locator('#map svg path');

    // ---- County tick: draws the boundary and reveals the center block ----
    await counties.first().click({ force: true });
    await expect.poll(() => paths.count(), { timeout: 15000 }).toBeGreaterThan(0);
    await expectMapSurvivedMorph(page, wireId, { paths: 'some' });

    // ---- Center tick: unit rows arrive with their geometry ----
    await expect(centers.first()).toBeVisible({ timeout: 15000 });
    await centers.first().click({ force: true });
    await expect.poll(() => unitRows.count(), { timeout: 15000 }).toBeGreaterThan(0);
    await expectMapSurvivedMorph(page, wireId, { paths: 'some' });

    // ---- Center off/on, three times: each pass morphs the unit list ----
    for (let round = 0; round < 3; round++) {
      await centers.first().click({ force: true });
      await expect.poll(() => unitRows.count(), { timeout: 15000 }).toBe(0);
      // The county boundary belongs to a different layer set and must stay.
      await expectMapSurvivedMorph(page, wireId, { paths: 'some' });

      await centers.first().click({ force: true });
      await expect.poll(() => unitRows.count(), { timeout: 15000 }).toBeGreaterThan(0);
      await expectMapSurvivedMorph(page, wireId, { paths: 'some' });
    }

    // ---- County off/on, three times: each pass inserts/removes whole blocks ----
    for (let round = 0; round < 3; round++) {
      await counties.first().click({ force: true });
      await expect.poll(() => paths.count(), { timeout: 15000 }).toBe(0);
      await expectMapSurvivedMorph(page, wireId, { paths: 'none' });

      await counties.first().click({ force: true });
      await expect.poll(() => paths.count(), { timeout: 15000 }).toBeGreaterThan(0);
      await expectMapSurvivedMorph(page, wireId, { paths: 'some' });
    }

    // ---- Typing in the search box is a parent round trip, not a re-mount ----
    const search = page.locator('#unitMenu input[type="text"]');
    const livewireResponse = page.waitForResponse(
      (response) => /\/livewire[^/]*\/update$/.test(response.url()) && response.status() === 200,
      { timeout: 15000 },
    );
    await search.fill('بهداشت');
    await livewireResponse;

    await expectMapSurvivedMorph(page, wireId, { paths: 'some' });

    expect(errors, `console errors on /maps/unit:\n${errors.join('\n')}`).toEqual([]);
  });
});

test.describe('maps/unit — SPA navigation still releases the old map (#028 + #1221)', () => {
  test('unit → point → unit keeps one container and releases the previous instance', async ({ page }) => {
    const errors = collectErrors(page);
    await stubTiles(page);
    await login(page);

    await page.goto('/maps/unit');
    await waitForMap(page);
    await mark(page);

    const unitContainerIsConnected = () =>
      page.evaluate(
        (elementKey) => (window as unknown as Record<string, Element | null>)[elementKey]?.isConnected ?? null,
        MAP_ELEMENT,
      );

    expect(await unitContainerIsConnected()).toBe(true);

    await navigateByMenu(page, '/maps/point');

    // The point page builds its own map from the same store; the unit page's
    // container must be gone, not lingering detached in the document.
    expect(await unitContainerIsConnected(), 'the /maps/unit container must be released on navigate').toBe(false);
    expect(
      await page.locator('.leaflet-container').count(),
      'a SPA navigation must leave exactly one container',
    ).toBe(1);
    expect(await page.locator('#map .leaflet-marker-icon').count()).toBeGreaterThan(100);

    await navigateByMenu(page, '/maps/unit');

    expect(await page.locator('.leaflet-container').count()).toBe(1);
    const state = await page.evaluate(() => {
      const w = window as unknown as {
        Alpine?: { store: (name: string) => { get: () => { getContainer(): HTMLElement } | null } };
      };
      const map = w.Alpine?.store('map')?.get() ?? null;
      return {
        bound: !!map && map.getContainer() === document.querySelector('#map'),
        paths: document.querySelectorAll('#map svg path').length,
      };
    });
    expect(state.bound, 'the store must be bound to the #map of the page we returned to').toBe(true);
    expect(state.paths, 'a fresh /maps/unit starts with no geometry').toBe(0);

    expect(errors, `console errors during SPA navigation:\n${errors.join('\n')}`).toEqual([]);
  });
});
