import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #1532 — the map and the Zabbix charts authenticate `/api/*` from the
 * session cookie (#1399). Sanctum only treats such a request as stateful when
 * its Referer/Origin host is in `config('sanctum.stateful')`; a deployment
 * served from a host/port missing from that list answered
 * `401 {"message":"Unauthenticated."}` to every call.
 *
 * The old map handled that badly: `fetchAndRender()` parsed the 401 envelope,
 * found no `features`, cleared the layer, and every later pan fired another six
 * doomed requests — no error, no hint, just an empty map. The fix is a shared
 * terminal latch (`resources/js/api-fetch.js`) plus exactly ONE banner.
 *
 * The 401 is trapped at the network layer on purpose: it needs no broken
 * deployment to reproduce, and it pins the client contract (one request per
 * layer, one banner, zero retries) independently of the server config.
 */

/**
 * Leaflet keeps fetching tiles forever, so `networkidle` never settles on a
 * map page. Same wait shape as `disease-layer.spec.ts`.
 */
async function waitForMap(page: import('@playwright/test').Page) {
  await page.waitForLoadState('load');
  await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForTimeout(1500);
}

test.describe('maps — API auth latch (#1532)', () => {
  test('a 401 on /api/gis/* fires one request per layer, one banner, and no retry', async ({ page }) => {
    await login(page);

    const requests: string[] = [];
    await page.route('**/api/gis/**', (route) => {
      requests.push(new URL(route.request().url()).pathname);

      return route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Unauthenticated.' }),
      });
    });

    await page.goto('/map');
    await waitForMap(page);

    const banner = page.getByTestId('api-auth-banner');
    await expect(banner).toBeVisible({ timeout: 15000 });

    // One request per active layer — `units` is the only layer on by default —
    // plus the stats refresh. Nothing else may be retried.
    expect(requests.filter((path) => path.endsWith('/units'))).toHaveLength(1);
    expect(requests.filter((path) => path.endsWith('/stats'))).toHaveLength(1);

    const initialRequestCount = requests.length;

    // Pan three times. Every move used to re-fire the whole layer set.
    const box = await page.locator('#map').boundingBox();
    if (!box) throw new Error('#map has no bounding box');

    for (let pan = 1; pan <= 3; pan++) {
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      await page.mouse.down();
      await page.mouse.move(box.x + box.width / 2 - pan * 80, box.y + box.height / 2 - pan * 40, {
        steps: 5,
      });
      await page.mouse.up();
      await page.waitForTimeout(800);
    }

    await expect(page.getByTestId('api-auth-banner')).toHaveCount(1);
    expect(requests).toHaveLength(initialRequestCount);
  });

  test('the banner stays hidden while the API answers', async ({ page }) => {
    await login(page);
    await page.goto('/map');
    await waitForMap(page);

    // `.unit-marker` proves the layer payload rendered, so a visible banner
    // here would be a false positive rather than "the API failed quietly".
    await expect(page.locator('.unit-marker').first()).toBeVisible({ timeout: 15000 });
    await expect(page.getByTestId('api-auth-banner')).toBeHidden();
  });
});
