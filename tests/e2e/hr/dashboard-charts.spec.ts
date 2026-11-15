import { test, expect, login } from '../shared/fixtures';
import { installThemeHelpers, setTheme, BLOCKING_THEMES } from '../theme/theme-helpers';

/**
 * Issue #1499 — /hr-dashboard refreshed about once a second and its charts
 * flashed.
 *
 * Mechanism (confirmed against the locked Livewire vendor bundle): the
 * `morphed` hook in dashboard-charts.js called `renderHrCharts()`, which did
 * `component.call('chartPayload')`. Every Livewire method call renders HTML,
 * every rendered HTML effect morphs, and `morph2()` fires `morphed` with no
 * equality short-circuit — so the hook re-entered itself as fast as the server
 * answered. Each morph also wipes the chart SVG (the containers are empty in
 * the server HTML), and `renderHrBar()` rebuilds it: the flash.
 *
 * The fix makes the paint read a server-rendered inert block instead of the
 * server, the same shape as `#dashboard-chart-data` (#1352) and the #1042
 * reports' `data-chart`. The last two assertions separate that fix from
 * "delete the hook": a morph still wipes the SVG, so the repaint must happen —
 * with zero requests.
 */

/**
 * Livewire 4 mounts its update endpoint under a build-hashed prefix
 * (`POST livewire-46246a44/update`), so match the shape rather than a literal
 * path — `/livewire/update` and `/livewire-<hash>/update` both qualify.
 */
const LIVE_UPDATES = (urls: string[]) =>
  urls.filter((url) => /\/livewire[^/]*\/update$/.test(new URL(url).pathname));

const CHART_IDS = ['#hrChartByUnit', '#hrChartBySemat', '#hrChartByTahsil', '#hrChartByEstekhdam'];

test.describe('HR dashboard charts (#1499)', () => {
  test('no Livewire update after load, and charts survive a real morph', async ({ page }) => {
    await login(page);
    await page.goto('/hr-dashboard');
    await page.waitForLoadState('networkidle');

    // First paint done (Highcharts loads from @assets before the hook paints).
    for (const id of CHART_IDS) {
      await expect(page.locator(`${id} svg`)).toHaveCount(1);
    }

    const posts: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST') posts.push(req.url());
    });
    await page.waitForTimeout(3000);

    // Before the fix this window holds ~3 updates (one per round-trip).
    expect(LIVE_UPDATES(posts).length, 'the HR dashboard must not poll the server').toBe(0);
    for (const id of CHART_IDS) {
      await expect(page.locator(`${id} svg`)).toHaveCount(1);
    }
    expect(await page.evaluate(() => (window as any).Highcharts.charts.filter(Boolean).length)).toBe(4);

    // A real morph still repaints (morph wipes the SVG): the help modal commits
    // `showHelpModal` with `$set` (components/help/button.blade.php:12).
    await page.click('button[aria-label="نمایش راهنمای hr-dashboard"]');
    await page.waitForTimeout(1000);
    for (const id of CHART_IDS) {
      await expect(page.locator(`${id} svg`)).toHaveCount(1);
    }
    expect(await page.evaluate(() => (window as any).Highcharts.charts.filter(Boolean).length)).toBe(4);
  });
});

/**
 * Issue #1530 — the value label at the end of each HR bar rendered as a black
 * smudge.
 *
 * Mechanism: `renderHrBar` set only `style.color`, so Highcharts' deep merge
 * kept the `plotOptions.series.dataLabels.style` defaults —
 * `textOutline: '1px contrast'`, `fontWeight: 'bold'`, `fontSize: '0.7em'`.
 * `applyTextOutline()` resolves `contrast` with `renderer.getContrast()`, whose
 * `tk.parse` understands hex/rgb/named only: the oklch fill from
 * `--color-base-content` falls through to `#000000`, and the 1px outline is
 * widened to 2px. A 2px black halo behind 11px bold oklch digits on a light
 * card is the smudge. The label sits on the card, outside the bar, so the halo
 * has no job.
 *
 * The assertions here are structural on purpose: contrast alone cannot see this
 * bug — `measureText` hides the glyph and samples the card behind it, so it
 * reports ~17.7:1 while the glyph is still unreadable. `textOutline: 'none'`
 * removes the `.highcharts-text-outline` tspan; weight/size/fill pin the other
 * two inherited defaults and the theme token, so a later change to a hex token
 * (which would turn the halo white) cannot silently regress either.
 */

interface LabelFacts {
  count: number;
  outlines: number;
  weights: string[];
  sizes: number[];
  /** Largest per-channel RGB distance between the label fill and the theme token. */
  fillDelta: number;
  /** How far the widest label box reaches past the chart container (px). */
  overflowRight: number;
}

async function measureValueLabels(
  page: import('@playwright/test').Page,
  chartId: string
): Promise<LabelFacts> {
  return page.evaluate((id) => {
    const t = (window as unknown as { __theme: { toRGB: (s: string) => number[] } }).__theme;
    const token = getComputedStyle(document.documentElement)
      .getPropertyValue('--color-base-content')
      .trim();
    const expected = t.toRGB(token);

    const container = document.getElementById(id.slice(1))!;
    const containerRight = container.getBoundingClientRect().right;
    const nodes = Array.from(container.querySelectorAll('.highcharts-data-label'));
    const rects = nodes.map((n) => n.getBoundingClientRect());

    const deltas = nodes.map((n) => {
      const fill = getComputedStyle(n.querySelector('text')!).fill;
      return Math.max(...t.toRGB(fill).map((v, i) => Math.abs(v - expected[i])));
    });

    return {
      count: nodes.length,
      outlines: nodes.reduce(
        (sum, n) => sum + n.querySelectorAll('.highcharts-text-outline').length,
        0
      ),
      weights: nodes.map((n) => getComputedStyle(n.querySelector('text')!).fontWeight),
      sizes: nodes.map((n) => parseFloat(getComputedStyle(n.querySelector('text')!).fontSize)),
      fillDelta: deltas.length ? Math.max(...deltas) : 999,
      overflowRight: rects.length
        ? Math.max(...rects.map((r) => Math.ceil(r.right - containerRight)))
        : 0,
    };
  }, chartId);
}

test.describe('HR dashboard value labels (#1530)', () => {
  /**
   * Row overlap is deliberately NOT asserted. The seeded e2e database has 113
   * units, so `#hrChartByUnit` would need 113 × 28px = 3164px, but the
   * container is 400px: the `#hrChartByUnit { height: … }` block in
   * `hr/dashboard.blade.php` is a column-0 `<style>` in a Livewire 4
   * single-file component, which `SingleFileParser::extractStylePortion()`
   * pulls out as a CSS module — written verbatim (`{{ max(… ) }}` stays raw,
   * i.e. invalid CSS) and never linked. Every row is ~3.5px tall, so labels
   * overlap with or without this fix; that is a separate pre-existing #1352
   * regression, reported on #1530. This spec still pins what #1530 owns: the
   * per-label style and that no label is clipped by the container.
   */
  test('all four bar charts render outline-free 12px labels in the theme colour', async ({
    page,
  }) => {
    test.setTimeout(180_000);
    await installThemeHelpers(page);
    await login(page);
    await page.goto('/hr-dashboard');
    await page.waitForLoadState('networkidle');

    for (const theme of BLOCKING_THEMES) {
      // A reload (not just the attribute) so the chart paints with this theme's
      // palette: `renderHrBar` reads `--color-base-content` at paint time.
      await setTheme(page, theme);
      await page.reload({ waitUntil: 'networkidle' });

      for (const id of CHART_IDS) {
        await expect(page.locator(`${id} svg`)).toHaveCount(1);
        const facts = await measureValueLabels(page, id);
        const where = `${theme} ${id}`;

        expect(facts.count, `${where}: value labels rendered`).toBeGreaterThan(0);
        expect(facts.outlines, `${where}: .highcharts-text-outline nodes`).toBe(0);
        expect([...new Set(facts.weights)], `${where}: data-label font-weight`).toEqual(['400']);
        expect([...new Set(facts.sizes)], `${where}: data-label font-size`).toEqual([12]);
        expect(
          facts.fillDelta,
          `${where}: label fill vs --color-base-content`
        ).toBeLessThanOrEqual(1.5);
        expect(
          facts.overflowRight,
          `${where}: label box inside the chart container`
        ).toBeLessThanOrEqual(0);
      }
    }
  });
});
