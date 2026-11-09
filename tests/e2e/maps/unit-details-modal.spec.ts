import { test, expect, login } from '../shared/fixtures';
import type { Page } from '@playwright/test';

/**
 * Issue #1383 — the unit-details modal on /map never opened.
 *
 * Two independent defects, both client-side and therefore invisible to Pest:
 *
 *   1. the modal's directive expressions were written for the standard Alpine
 *      build. Under the CSP-safe build they fail the interpreter, so the
 *      `showUnitDetails` handler and the five `x-text` bindings throw
 *      `CSP Parser Error` and the dialog never opens.
 *   2. the trigger itself, `onFeatureClick`, called `this.$wire(...)` — the
 *      `$wire` proxy has no apply trap, so a real marker click threw
 *      `TypeError: this.$wire is not a function` and no event was dispatched.
 *
 * This spec drives the real user path: a `.unit-marker` click on /map. The
 * console collector is armed before the click and must stay empty. The name
 * field must fill in from the server payload after the Livewire round-trip.
 *
 * Helpers are copied from `tests/e2e/maps/unit-filters.spec.ts` (do not
 * cross-import between specs).
 */

const TILE_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

async function stubTiles(page: Page): Promise<void> {
  await page.route(/tile\.openstreetmap\.org/, (route) =>
    route.fulfill({ status: 200, contentType: 'image/png', body: TILE_PNG }),
  );
}

/**
 * Wait for the map container and for the store to be bound to it. `networkidle`
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

/**
 * Open the sidebar submenu when collapsed, click the link, and wait for the
 * target page's map. Mirrors `tests/e2e/maps/spa-navigation.spec.ts`: the maps
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

/**
 * Arm the collector BEFORE the click: the handler expression is parsed lazily,
 * so the failure only surfaces when the event fires. Any `pageerror` (the
 * non-callable `$wire` TypeError) is collected too.
 */
function collectCspErrors(page: Page): string[] {
  const errors: string[] = [];

  page.on('console', (message) => {
    if (/Alpine Expression Error|CSP Parser Error/.test(message.text())) errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));

  return errors;
}

/**
 * The `wire:navigate` swap is asynchronous: the URL changes before the new DOM
 * and its Alpine tree are in place, and `waitForMap`'s store check can still
 * pass against the outgoing page (both pages render a `#map`). Wait for the
 * destination-only `unitDetailsModal` component to be initialized, so the
 * window listener and the fresh markers are both live before the click.
 */
async function waitForUnitDetailsComponent(page: Page): Promise<void> {
  await expect
    .poll(
      () =>
        page.evaluate(() => {
          const el = document.querySelector('[x-data="unitDetailsModal"]') as unknown as {
            _x_dataStack?: unknown[];
          } | null;
          return !!el && Array.isArray(el._x_dataStack) && el._x_dataStack.length > 0;
        }),
      { timeout: 15000, message: 'the unitDetailsModal Alpine component must be initialized' },
    )
    .toBe(true);
}

/**
 * Click a unit marker that is genuinely the topmost element at its centre.
 *
 * A bare `.unit-marker.first().click({ force: true })` is not deterministic:
 * `force` skips actionability but still dispatches at the element's centre, so
 * an overlay (the map toolbar spans the top strip, z-10) silently swallows the
 * click. Earlier specs change the unit set, so "first" can land under it. Pick
 * a marker that `elementFromPoint` resolves to, below the toolbar, and click
 * those coordinates.
 */
async function clickUnitMarker(page: Page): Promise<void> {
  const point = await page.evaluate(() => {
    const map = document.querySelector('#map');
    if (!map) return null;
    const box = map.getBoundingClientRect();

    for (const marker of Array.from(document.querySelectorAll('.unit-marker'))) {
      const rect = marker.getBoundingClientRect();
      const x = rect.left + rect.width / 2;
      const y = rect.top + rect.height / 2;

      if (x < box.left + 20 || x > box.right - 20) continue;
      if (y < box.top + 80 || y > box.bottom - 20) continue;

      const hit = document.elementFromPoint(x, y);
      if (hit === marker || (hit instanceof Node && marker.contains(hit))) {
        return { x, y };
      }
    }

    return null;
  });

  expect(point, 'an unobstructed unit marker must be visible on the map').not.toBeNull();
  await page.mouse.click(point!.x, point!.y);
}

/**
 * Click a real unit marker and assert the whole modal contract: it opens, the
 * five fields render from the server payload, the spinner clears, the console
 * is clean, and the dialog can be closed.
 */
async function expectUnitDetailsModal(page: Page): Promise<void> {
  await waitForUnitDetailsComponent(page);

  const errors = collectCspErrors(page);

  await clickUnitMarker(page);

  const dialog = page.locator('dialog[open]');
  await expect(dialog, 'a unit-marker click must open the details dialog').toBeVisible({ timeout: 15000 });
  await expect(page.locator('.modal-box')).toContainText('جزئیات واحد');

  const fields = page.locator('.modal-box .space-y-2 > div');
  const nameField = fields.nth(0).locator('span');
  const typeField = fields.nth(1).locator('span');
  const locationField = fields.nth(2).locator('span');
  const childrenField = fields.nth(3).locator('span');

  // The name arrives from the Livewire round-trip (`$wire.loadUnitDetails`).
  await expect(nameField).not.toHaveText('', { timeout: 15000 });
  await expect(typeField).not.toHaveText('');
  await expect(locationField).not.toHaveText('');
  await expect(childrenField).not.toHaveText('');

  // `x-show="loading"` must have cleared once the payload is in.
  await expect(page.locator('.modal-box .loading')).toBeHidden();

  // The direct regression assertion: an empty collector after the interaction.
  expect(errors, `console errors while opening the unit-details modal:\n${errors.join('\n')}`).toEqual([]);

  // Hardening: the modal is interactive, not merely visible.
  await page.locator('.modal-box button', { hasText: 'بستن' }).click();
  await expect(page.locator('dialog[open]')).toBeHidden();
}

test.describe('maps — unit details modal (#1383)', () => {
  test('opens from a unit-marker click with a clean console (fresh load)', async ({ page }) => {
    await stubTiles(page);
    await login(page);

    await page.goto('/map');
    await waitForMap(page);

    await expectUnitDetailsModal(page);
  });

  test('opens after a wire:navigate from /maps/county', async ({ page }) => {
    await stubTiles(page);
    await login(page);

    await page.goto('/maps/county');
    await waitForMap(page);

    await navigateByMenu(page, '/map');

    await expectUnitDetailsModal(page);
  });
});
