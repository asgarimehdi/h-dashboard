import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #1063 — the geocoding call moved from `axios.get` to the native `fetch`.
 *
 * The API-level shape cannot be asserted from PHP, and the repo has no JS test
 * runner, so the three behaviours that a library swap breaks silently are
 * exercised here in a real browser against a mocked Nominatim:
 *
 * 1. the query string is encoded (axios used to do it; a manual `'?q=' + query`
 *    would truncate on `&`/`#`),
 * 2. a non-array JSON body is rejected without an uncaught TypeError,
 * 3. a 4xx/5xx status — which `fetch`, unlike axios, does NOT reject — is
 *    handled by the explicit `response.ok` branch.
 *
 * Only `/maps/route2` renders this code, so the mock lives on the geocoding
 * URL's `/search` path; the routing service is left alone (its failure is
 * unrelated and only logs a console error).
 */

const GEOCODE_PATH = '/search';

async function openRoute2(page: import('@playwright/test').Page) {
  const pageErrors: string[] = [];
  const consoleErrors: string[] = [];
  const dialogs: string[] = [];

  page.on('pageerror', (error) => pageErrors.push(String(error)));
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('dialog', (dialog) => {
    dialogs.push(dialog.message());
    void dialog.dismiss();
  });

  await login(page);
  await page.goto('/maps/route2');
  await page.locator('#map').waitFor({ state: 'visible', timeout: 15000 });
  // Let the store hand back a live instance and bind window.searchRoute.
  await page.waitForTimeout(1500);

  return { pageErrors, consoleErrors, dialogs };
}

async function requestRoute(page: import('@playwright/test').Page, start: string, end: string) {
  await page.fill('#start-input', start);
  await page.fill('#end-input', end);
  await page.getByRole('button', { name: 'محاسبه مسیر' }).click();
}

test('geocoding query is URL-encoded and every lookup is issued once', async ({ page }) => {
  const { pageErrors } = await openRoute2(page);

  const queries: string[] = [];
  await page.route(
    (url) => url.pathname.endsWith(GEOCODE_PATH),
    async (route) => {
      const url = new URL(route.request().url());
      queries.push(url.searchParams.get('q') ?? '');
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify([{ lat: 35.6892, lon: 51.389, display_name: 'تهران' }]),
      });
    },
  );

  // A space and a Persian comma — the shapes a naive concatenation mishandles.
  await requestRoute(page, 'خیابان آزادی، تهران', 'میدان نقش جهان، اصفهان');

  await expect.poll(() => queries.length, { timeout: 10000 }).toBe(2);
  expect(queries).toEqual(['خیابان آزادی، تهران', 'میدان نقش جهان، اصفهان']);

  // The happy path must not raise: parsing an array result never throws.
  expect(pageErrors).toEqual([]);
});

test('a non-array geocoding body is rejected without an uncaught error', async ({ page }) => {
  const { pageErrors, dialogs } = await openRoute2(page);

  await page.route(
    (url) => url.pathname.endsWith(GEOCODE_PATH),
    (route) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ error: 'boom' }) }),
  );

  await requestRoute(page, 'تهران', 'اصفهان');

  // No coordinates resolved, so the page reports an invalid origin/destination
  // instead of dying inside the parser.
  await expect.poll(() => dialogs.length, { timeout: 10000 }).toBe(1);
  expect(dialogs[0]).toContain('معتبر');
  expect(pageErrors).toEqual([]);
});

test('an HTTP error is handled by the response.ok branch, not the catch', async ({ page }) => {
  const { pageErrors, consoleErrors, dialogs } = await openRoute2(page);

  await page.route(
    (url) => url.pathname.endsWith(GEOCODE_PATH),
    (route) => route.fulfill({ status: 503, contentType: 'text/plain', body: 'unavailable' }),
  );

  await requestRoute(page, 'تهران', 'اصفهان');

  await expect
    .poll(() => consoleErrors.find((message) => message.includes('Geocoding error')), { timeout: 10000 })
    .toBeTruthy();
  // fetch resolves on 503; the explicit branch logs the status instead of
  // letting `response.json()` throw on the plain-text body.
  expect(consoleErrors.some((message) => message.includes('503'))).toBeTruthy();
  expect(dialogs.length).toBe(1);
  expect(pageErrors).toEqual([]);
});
