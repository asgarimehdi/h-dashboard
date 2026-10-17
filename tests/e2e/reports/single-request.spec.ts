import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #1042 — a filter change used to fire a second `$wire.chartPayload()`
 * Livewire method request on top of the `wire:model.live` render that had
 * already computed the same aggregates. The three report scripts now read a
 * server-rendered `data-chart` payload (like `reports/persons`, #858), so one
 * filter change must cost exactly one `/livewire/update` request.
 *
 * This is the mechanical form of the issue's acceptance criterion
 * ("یک تغییر فیلتر ⇒ یک درخواست"); the Feature tests can only prove the
 * payload reaches the DOM and that no JS `chartPayload()` call remains.
 */

/**
 * Livewire 4 mounts its update endpoint under a build-hashed prefix
 * (`POST livewire-46246a44/update`), so match the shape rather than a literal
 * path — `/livewire/update` and `/livewire-<hash>/update` both qualify.
 */
const LIVE_UPDATES = (requests: string[]) =>
  requests.filter((url) => /\/livewire[^/]*\/update$/.test(new URL(url).pathname));

test.describe('reports filter changes cost one request', () => {
  test('units report: changing the unit type fires a single Livewire update', async ({ page }) => {
    await login(page);
    await page.goto('/reports/units');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000); // let Highcharts finish its first render

    const requests: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST') requests.push(req.url());
    });

    const select = page.locator('select[wire\\:model\\.live="selectedUnitTypeId"]');
    test.skip((await select.locator('option').count()) < 2, 'no unit type options to pick from');

    await select.selectOption({ index: 1 });
    await page.waitForTimeout(2500); // let any second request land

    expect(LIVE_UPDATES(requests).length, 'one filter change must fire exactly one Livewire update').toBe(1);
  });

  test('advanced report: changing the report type fires a single Livewire update', async ({ page }) => {
    await login(page);
    await page.goto('/reports/tickets');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500); // let Highcharts finish its first render

    const requests: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST') requests.push(req.url());
    });

    const select = page.locator('select[wire\\:model\\.live="reportType"]');
    await select.selectOption({ index: 1 });
    await page.waitForTimeout(2500);

    expect(LIVE_UPDATES(requests).length, 'one filter change must fire exactly one Livewire update').toBe(1);
  });
});
