import { execSync } from 'child_process';
import type { Page } from '@playwright/test';
import { test, expect, login, TEST_USER, runId } from '../shared/fixtures';

/**
 * Issue #1920 — stored XSS via unit names in Leaflet popups.
 *
 * Leaflet parses a string passed to `bindPopup` as HTML. Both popup builders
 * interpolated the unit name raw:
 *   - resources/js/map-layers.js      → /maps/point
 *   - resources/js/map-pages.js       → /reports/map-no-boundary
 *
 * This spec creates one unit whose name carries markup, then asserts both
 * pages render the name as literal text: no `<img>`/`<script>` element is
 * created and no handler fires.
 */

const TOKEN = `XSS-${runId}`;
const PAYLOAD = `${TOKEN} <img src=x onerror="window.__xss=1"><script>window.__xss=2</script>`;

let unitId = '';

test.beforeAll(() => {
  const b64 = Buffer.from(PAYLOAD).toString('base64');
  unitId = execSync(`php tests/e2e/create-markup-unit.php ${TEST_USER.nCode} "${b64}" 36.6 48.5`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  }).trim();
});

test.afterAll(() => {
  if (!unitId) return;
  execSync(`php tests/e2e/create-markup-unit.php --delete ${unitId}`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
});

/**
 * Popup content is a string only when it was bound as HTML (`bindPopup(str)`);
 * a callback-bound popup resolves later and is not part of this contract.
 * `/maps/point` nests markers inside an `L.layerGroup`, so the walk recurses.
 */
function collectPopups(): string[] {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const map = (window as any).Alpine.store('map').get();
  const out: string[] = [];
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const walk = (layer: any): void => {
    const content = layer.getPopup?.()?.getContent?.();
    if (typeof content === 'string') out.push(content);
    layer.eachLayer?.(walk);
  };
  map.eachLayer(walk);

  return out;
}

async function assertEscaped(page: Page, url: string): Promise<void> {
  await login(page);
  await page.goto(url);

  await page.waitForFunction(
    (token) => {
      try {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const map = (window as any).Alpine.store('map').get();
        let found = false;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const walk = (layer: any): void => {
          const content = layer.getPopup?.()?.getContent?.();
          if (typeof content === 'string' && content.includes(token)) found = true;
          layer.eachLayer?.(walk);
        };
        map.eachLayer(walk);

        return found;
      } catch {
        return false;
      }
    },
    TOKEN,
    { timeout: 30000 },
  );

  const contents = await page.evaluate(collectPopups);
  const ours = contents.find((content) => content.includes(TOKEN));
  expect(ours).toBeTruthy();
  expect(ours).toContain('&lt;img');
  expect(ours).not.toContain('<img');
  expect(ours).not.toContain('<script');

  // Open the popup so Leaflet parses the bound string into the DOM.
  await page.evaluate((token) => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const map = (window as any).Alpine.store('map').get();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const walk = (layer: any): void => {
      const content = layer.getPopup?.()?.getContent?.();
      if (typeof content === 'string' && content.includes(token)) layer.openPopup();
      layer.eachLayer?.(walk);
    };
    map.eachLayer(walk);
  }, TOKEN);

  const popup = page.locator('.leaflet-popup-content').first();
  await expect(popup).toContainText(PAYLOAD);
  expect(await page.locator('.leaflet-popup-content img, .leaflet-popup-content script').count()).toBe(0);
  expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
}

test('point popup escapes unit name', async ({ page }) => {
  await assertEscaped(page, '/maps/point');
});

test('no-boundary popup escapes unit name', async ({ page }) => {
  await assertEscaped(page, '/reports/map-no-boundary');
});
