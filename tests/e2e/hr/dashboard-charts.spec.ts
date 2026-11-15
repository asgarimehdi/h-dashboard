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
   * Row overlap is deliberately NOT asserted here: it is pinned by the #1536
   * spec below. When this spec was written the height rule was dead — the
   * `#hrChartByUnit { height: … }` block in `hr/dashboard.blade.php` is a
   * column-0 `<style>` in a Livewire 4 single-file component, which
   * `SingleFileParser::extractStylePortion()` pulls out as a CSS module —
   * written verbatim (`{{ max(… ) }}` stays raw, i.e. invalid CSS) and never
   * linked — so every row was ~3.5px tall and labels overlapped with or
   * without the halo fix. #1536 moved the rule into `dashboard-charts.js`, so
   * the seeded 113 units now get 3164px and labels render at step 1. This spec
   * pins what #1530 owns: the per-label style and that no label is clipped by
   * the container.
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

/**
 * Issue #1536 — chart heights are data-driven (#1352's rule) but the rule lived
 * in a `<style nonce>` block inside the single-file component. Livewire's
 * SingleFileParser::extractStylePortion() lifts that tag out verbatim and Blade
 * never translates it, so `{{ max(240, count($byUnit) * 28) }}` stayed literal
 * CSS: a dead module. Every chart therefore fell back to Highcharts' 400px box,
 * where 113 category labels get thinned (step 4) and overlap.
 *
 * The rule now runs in dashboard-charts.js off the same `rows` array that feeds
 * the series. Expectations are derived from the rendered payload, so a seed
 * change cannot break them — only a broken rule can.
 */
const HR_CHARTS = [
  { id: '#hrChartByUnit', key: 'byUnit', rowHeight: 28 },
  { id: '#hrChartBySemat', key: 'bySemat', rowHeight: 28 },
  { id: '#hrChartByTahsil', key: 'byTahsil', rowHeight: 32 },
  { id: '#hrChartByEstekhdam', key: 'byEstekhdam', rowHeight: 32 },
] as const;

test.describe('HR dashboard chart heights (#1536)', () => {
  test('chart heights follow the row counts', async ({ page }) => {
    await login(page);
    await page.goto('/hr-dashboard');
    await page.waitForLoadState('networkidle');

    const payload = await page.evaluate(() =>
      JSON.parse(document.getElementById('hr-chart-data')!.getAttribute('data-chart') || '{}'));

    // Non-vacuity: under ~15 rows the 240px floor makes every assertion pass.
    expect(payload.byUnit.length).toBeGreaterThanOrEqual(15);

    for (const { id, key, rowHeight } of HR_CHARTS) {
      // The charts paint from waitForHighchartsHr's poll, not from networkidle.
      await page.waitForSelector(`${id} svg`, { state: 'attached' });

      const expected = Math.max(240, payload[key].length * rowHeight);
      const m = await page.evaluate((sel) => {
        const el = document.querySelector(sel)!;
        const chart = (window as any).Highcharts.charts.find((c: any) => c?.renderTo === el);
        const boxes = [...el.querySelectorAll('.highcharts-xaxis-labels text')]
          .map((t) => t.getBoundingClientRect()).sort((a, b) => a.top - b.top);

        return {
          css: parseFloat(getComputedStyle(el).height),
          chartHeight: chart?.chartHeight,
          labels: boxes.length,
          overlaps: boxes.slice(1).filter((b, i) => b.top < boxes[i].bottom - 0.5).length,
        };
      }, id);

      expect(m.css, `${id} container height`).toBe(expected);
      expect(m.chartHeight, `${id} Highcharts height (not the 400 fallback)`).toBe(expected);
      expect(m.labels, `${id} every category label rendered (step 1)`).toBe(payload[key].length);
      expect(m.overlaps, `${id} no two labels overlap`).toBe(0);
    }
  });

  test('height survives a real morph and a theme switch, set via CSSOM', async ({ page }) => {
    await login(page);
    await page.goto('/hr-dashboard');
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('#hrChartByUnit svg', { state: 'attached' });

    const payload = await page.evaluate(() =>
      JSON.parse(document.getElementById('hr-chart-data')!.getAttribute('data-chart') || '{}'));
    const expectedUnit = Math.max(240, payload.byUnit.length * 28);

    const unitHeight = () => page.evaluate(() =>
      parseFloat(getComputedStyle(document.getElementById('hrChartByUnit')!).height));
    const liveCharts = () => page.evaluate(
      () => (window as any).Highcharts.charts.filter(Boolean).length);

    // The height is a runtime CSSOM assignment, never a `style=` attribute
    // (style-src-attr is not in the policy) and never an inline <style>.
    expect(await page.evaluate(() => document.getElementById('hrChartByUnit')!.getAttribute('style')))
      .toContain('height');
    expect(await unitHeight()).toBe(expectedUnit);

    // A theme switch repaints from the cached payload — same rows, same height.
    const themeBefore = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
    await page.locator('label:has(input.theme-controller)').first().click();
    await expect.poll(() => page.evaluate(() => document.documentElement.getAttribute('data-theme')))
      .not.toBe(themeBefore);
    await page.waitForSelector('#hrChartByUnit svg', { state: 'attached' });
    expect(await unitHeight()).toBe(expectedUnit);
    expect(await liveCharts()).toBe(4);

    // A real morph (#1499): the help modal commits `showHelpModal` with `$set`.
    // The `morphed` hook must put the same height back. Runs last — the modal
    // covers the page until it is dismissed.
    await page.click('button[aria-label="نمایش راهنمای hr-dashboard"]');
    await page.waitForTimeout(1000);
    expect(await unitHeight()).toBe(expectedUnit);
    expect(await liveCharts()).toBe(4);
  });
});
