import { expect, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import {
  contrastBetween,
  measureColorOn,
  measurePageBackground,
  measureText,
  setTheme,
} from './theme-helpers';

/**
 * The measured surface sweep, shared by the mandatory 7-theme spec and the
 * full 35-theme matrix (#1224, #1227).
 *
 * Keeping one implementation is the point: the blocking job and the full report
 * must measure the same cells the same way, or their tables cannot be
 * compared. Only the theme list and the reporting layer differ.
 */

export type Verdict = '✅' | '❌' | '🧱' | 'n/a';

export interface Row {
  theme: string;
  surface: string;
  fg: string;
  bg: string;
  ratio: number | null;
  threshold: number;
  method: string;
  verdict: Verdict;
  note?: string;
}

/**
 * Exactly the cells `sweepTheme()` measures, in order. The full matrix spec
 * seeds an `n/a` row for every one of them before a theme runs, so an aborted
 * theme still owns the full cell set instead of silently shrinking the table
 * (#1227).
 */
export const SURFACES = [
  'menu normal',
  'menu hover',
  'menu active',
  'submenu header',
  'toast info',
  'toast success',
  'toast warning',
  'toast error',
  'glass card',
  'glass modal',
  'form select',
  'form toggle knob',
  'button solid',
  'button ghost',
  'input placeholder',
  'badge solid',
  'badge outline',
  // #1226 — one probe per solid variant. The page row above only sees the
  // first `.badge:visible` (= primary); the per-variant strip pins every cell
  // of the opaque/glass matrix, in both the blocking sweep and the full 35.
  'badge primary',
  'badge secondary',
  'badge neutral',
  'badge info',
  'badge success',
  'badge warning',
  'badge error',
  'badge ghost',
  'leaflet zoom',
  'leaflet attribution',
  'dashboard trend line',
  'dashboard pie slice',
  'hr chart axis text',
  'hr chart bar',
  'stitch background',
  'dark: utilities',
] as const;

export type Surface = (typeof SURFACES)[number];

/**
 * Known structural findings that cannot be fixed in this PR (verdict 🧱).
 * Key: `${theme}:${surface}`. Value: the measured reason, published verbatim.
 *
 * #1226 (merged in a4276fc8) owns the badge pairs for its seven-theme matrix;
 * light/synthwave/fantasy `badge solid` cells are measured ✅ through that
 * matrix and no longer belong here. winter/abyss sit *outside* the #1226
 * matrix — their solid badges keep the glass re-tint with no opaque pair, so
 * they stay genuine structural findings tracked separately with the gatekeeper.
 * No per-theme pair is invented in #1227.
 */
export const STRUCTURAL = new Map<string, string>([
  ['winter:badge solid', 'badge solid 2.27:1 — glass re-tint, outside the #1226 opaque/glass matrix; needs per-theme badge pairs (separate issue with the gatekeeper)'],
  ['abyss:badge solid', 'badge solid 2.42:1 — glass re-tint, outside the #1226 opaque/glass matrix; needs per-theme badge pairs (separate issue with the gatekeeper)'],
]);

export interface SweepOptions {
  /** Screenshot filename prefix; screenshots are written only when set. */
  captureDir?: string;
  capturePrefix?: string;
}

export class SweepRecorder {
  readonly rows: Row[] = [];

  constructor(private readonly structural: Map<string, string> = STRUCTURAL) {}

  add(
    theme: string,
    surface: string,
    measurement: { fg: string; bg: string; ratio: number; method: string },
    threshold = 4.5,
    note?: string
  ): void {
    const structural = this.structural.get(`${theme}:${surface}`);
    this.rows.push({
      theme,
      surface,
      fg: measurement.fg,
      bg: measurement.bg,
      ratio: measurement.ratio,
      threshold,
      method: measurement.method,
      verdict: structural ? '🧱' : measurement.ratio >= threshold ? '✅' : '❌',
      note: structural ?? note,
    });
  }

  missing(theme: string, surface: string, note: string): void {
    this.rows.push({
      theme,
      surface,
      fg: '-',
      bg: '-',
      ratio: null,
      threshold: 4.5,
      method: '-',
      verdict: 'n/a',
      note,
    });
  }

  has(theme: string, surface: string): boolean {
    return this.rows.some((row) => row.theme === theme && row.surface === surface);
  }

  /**
   * Seed every (theme, surface) pair that was never measured. Run after all
   * themes so a thrown sweep still yields the full cross-product.
   */
  fillMatrix(themes: string[], note = 'not measured — the theme sweep aborted before this cell'): void {
    for (const theme of themes) {
      for (const surface of SURFACES) {
        if (!this.has(theme, surface)) {
          this.missing(theme, surface, note);
        }
      }
    }
  }

  verdicts(theme: string): Verdict[] {
    return this.rows.filter((row) => row.theme === theme).map((row) => row.verdict);
  }

  /** Themes with at least one ❌ (a real, fixable failure) or 🧱 (structural). */
  flaggedThemes(): string[] {
    return [...new Set(this.rows.filter((row) => row.verdict === '❌' || row.verdict === '🧱').map((row) => row.theme))];
  }

  failures(): Row[] {
    return this.rows.filter((row) => row.verdict === '❌');
  }
}

async function computedColor(
  page: Page,
  selector: string,
  property: 'color' | 'fill' | 'stroke' | 'backgroundColor' = 'color'
): Promise<string> {
  return page
    .locator(selector)
    .first()
    .evaluate((el, prop) => (getComputedStyle(el) as unknown as Record<string, string>)[prop], property);
}

async function placeholderColor(page: Page, selector: string): Promise<string> {
  return page.locator(selector).first().evaluate((el) => getComputedStyle(el, '::placeholder').color);
}

async function fireToast(page: Page, variant: string): Promise<void> {
  await page.evaluate((v) => {
    const w = window as unknown as { toast: (payload: unknown) => void };
    w.toast({
      toast: {
        type: v,
        title: 'حذف شد',
        description: 'سخت افزار PC-1 حذف شد',
        position: 'toast-bottom',
        icon: '',
        css: `alert-${v}`,
        timeout: 60000,
        noProgress: true,
        progressClass: null,
      },
    });
  }, variant);
  await expect(page.locator(`.alert.alert-${variant}`)).toBeVisible();
}

/**
 * #1226 — solid badge variants the sweep pins. The page row (`badge solid`)
 * only ever sees the first `.badge:visible` (= primary); several other
 * variants were sub-AA in dark/synthwave/fantasy/light/luxury before the
 * per-theme opaque/glass matrix landed in a4276fc8.
 */
const BADGE_VARIANTS = ['primary', 'secondary', 'neutral', 'info', 'success', 'warning', 'error', 'ghost'] as const;

/**
 * Inject one solid badge per variant into a fixed probe strip. Measured through
 * the same `measureText` pixel path as real badges, so glass/backdrop-filter is
 * part of the rendered result rather than computed from the CSS source.
 */
async function injectBadgeProbes(page: Page): Promise<void> {
  await page.evaluate((variants) => {
    document.getElementById('badge-probes')?.remove();
    const strip = document.createElement('div');
    strip.id = 'badge-probes';
    strip.style.cssText =
      'position:fixed;inset-inline-start:16px;bottom:16px;z-index:2147483647;display:flex;gap:8px;padding:8px;';
    for (const variant of variants) {
      const badge = document.createElement('span');
      badge.id = `badge-probe-${variant}`;
      badge.className = `badge badge-${variant}`;
      badge.textContent = 'نمونه';
      strip.appendChild(badge);
    }
    document.body.appendChild(strip);
  }, [...BADGE_VARIANTS]);
}

/**
 * Wait for a Highcharts container after a hard navigation.
 *
 * `renderTicketCharts` polls for the library (100 × 50 ms) and draws on hard
 * load. A cold script fetch can lose that race — seen once on `luxury` in the
 * 2026-10-23 re-baseline — so a reload runs the initializer again (the theme is
 * already persisted in localStorage, and `setTheme` is re-applied to keep the
 * settle/freeze state identical) before failing.
 */
async function waitForChart(page: Page, theme: string, selector: string): Promise<void> {
  try {
    await expect(page.locator(selector)).toBeVisible({ timeout: 15_000 });
  } catch {
    await page.reload({ waitUntil: 'networkidle' });
    await setTheme(page, theme);
    await expect(page.locator(selector)).toBeVisible({ timeout: 30_000 });
  }
}

async function capture(page: Page, theme: string, name: string, options: SweepOptions): Promise<void> {
  if (!options.captureDir) return;
  fs.mkdirSync(options.captureDir, { recursive: true });
  await page.screenshot({
    path: path.join(options.captureDir, `${options.capturePrefix ?? '1224'}-${theme}-${name}.png`),
    fullPage: true,
  });
}

/**
 * Measure every SURFACES cell for one theme. Throws on a missing selector (the
 * caller decides whether that aborts the run or just becomes an `n/a` cell).
 */
export async function sweepTheme(page: Page, theme: string, recorder: SweepRecorder, options: SweepOptions = {}): Promise<void> {
  // ---- 1. Menu: normal / hover / active / open submenu header ------------
  await page.goto('/kargozini/persons');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);

  const inactive = '.drawer-side .menu a:not([aria-current="page"])';
  recorder.add(theme, 'menu normal', await measureText(page, 'menu normal', inactive));
  await page.locator(inactive).first().hover();
  recorder.add(theme, 'menu hover', await measureText(page, 'menu hover', inactive));
  recorder.add(
    theme,
    'menu active',
    await measureText(page, 'menu active', '.drawer-side a.mary-active-menu[aria-current="page"]')
  );
  recorder.add(
    theme,
    'submenu header',
    await measureText(page, 'submenu header', '.drawer-side details[open] > summary')
  );
  await capture(page, theme, 'menu', options);

  // ---- 2. Alerts / toasts ------------------------------------------------
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  await capture(page, theme, 'dashboard', options);

  for (const variant of ['info', 'success', 'warning', 'error']) {
    await fireToast(page, variant);
    recorder.add(theme, `toast ${variant}`, await measureText(page, `toast ${variant}`, `.alert.alert-${variant}`));
  }

  // ---- 3. Glass blocks ---------------------------------------------------
  await page.goto('/settings');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);

  recorder.add(theme, 'glass card', await measureText(page, 'glass card', '.card h2'));

  await page.locator('button[title="راهنما"]').first().click();
  await expect(page.locator('.modal-box')).toBeVisible();
  recorder.add(theme, 'glass modal', await measureText(page, 'glass modal', '.modal-box'));
  await capture(page, theme, 'settings', options);
  await page.keyboard.press('Escape');

  // ---- 4. Form controls --------------------------------------------------
  const selectColor = await computedColor(page, '.select:visible', 'color');
  recorder.add(
    theme,
    'form select',
    await measureColorOn(page, 'form select', selectColor, '.select:visible', { x: 8, y: 8 })
  );

  // The track is glassed by app.css; the visible boundary is the knob
  // (::before, currentColor = --input-color), so measure knob vs track.
  const toggleTrack = await computedColor(page, '.toggle:visible', 'backgroundColor');
  const toggleKnob = await page
    .locator('.toggle:visible')
    .first()
    .evaluate((el) => getComputedStyle(el, '::before').backgroundColor);
  recorder.add(
    theme,
    'form toggle knob',
    await contrastBetween(page, 'form toggle knob', toggleKnob, toggleTrack),
    3
  );

  // ---- 5. Buttons / badges ----------------------------------------------
  await page.goto('/hardware');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  recorder.add(theme, 'button solid', await measureText(page, 'button solid', '.btn-success:visible'));
  recorder.add(theme, 'button ghost', await measureText(page, 'button ghost', '.btn-ghost:visible'));

  const inputPlaceholder = await placeholderColor(page, '.input:visible');
  recorder.add(
    theme,
    'input placeholder',
    await measureColorOn(page, 'input placeholder', inputPlaceholder, '.input:visible', {
      x: 8,
      y: 8,
    })
  );

  await page.goto('/users');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  if (await page.locator('.badge:visible').count()) {
    recorder.add(theme, 'badge solid', await measureText(page, 'badge solid', '.badge:visible'));
  } else {
    recorder.missing(theme, 'badge solid', 'no badge on /users');
  }
  if (await page.locator('.badge-outline:visible').count()) {
    recorder.add(theme, 'badge outline', await measureText(page, 'badge outline', '.badge-outline:visible'));
  } else {
    recorder.missing(theme, 'badge outline', 'no outline badge on /users');
  }

  // Every solid variant must clear AA, not just the first badge on the page
  // (#1226 — the per-theme opaque/glass matrix is pinned cell by cell).
  await injectBadgeProbes(page);
  for (const variant of BADGE_VARIANTS) {
    recorder.add(theme, `badge ${variant}`, await measureText(page, `badge ${variant}`, `#badge-probe-${variant}`));
  }
  await page.evaluate(() => document.getElementById('badge-probes')?.remove());

  // ---- 6. Leaflet controls ----------------------------------------------
  await page.goto('/maps/county');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  recorder.add(theme, 'leaflet zoom', await measureText(page, 'leaflet zoom', '.leaflet-control-zoom-in'));
  recorder.add(
    theme,
    'leaflet attribution',
    await measureText(page, 'leaflet attribution', '.leaflet-control-attribution')
  );
  await capture(page, theme, 'maps', options);

  // ---- 7. Charts ---------------------------------------------------------
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  await waitForChart(page, theme, '#ticketTrendChart svg');
  await page.waitForTimeout(400);

  // The palette is theme-aware, so read the rendered series colour instead of
  // hard-coding a hex (a stale hex would silently measure the wrong colour).
  const trendStroke = await computedColor(page, '#ticketTrendChart .highcharts-graph', 'stroke');
  recorder.add(
    theme,
    'dashboard trend line',
    await measureColorOn(page, 'dashboard trend line', trendStroke, '#ticketTrendChart', {
      x: 8,
      y: 8,
    }),
    3
  );
  const sliceFill = await computedColor(page, '#ticketStatusChart .highcharts-point', 'fill');
  recorder.add(
    theme,
    'dashboard pie slice',
    await measureColorOn(page, 'dashboard pie slice', sliceFill, '#ticketStatusChart', {
      x: 8,
      y: 8,
    }),
    3
  );

  await page.goto('/hr-dashboard');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  await waitForChart(page, theme, '#hrChartByUnit svg');
  await page.waitForTimeout(400);

  recorder.add(
    theme,
    'hr chart axis text',
    await measureText(page, 'hr chart axis text', '#hrChartByUnit .highcharts-axis-labels text', {
      colorProperty: 'fill',
    })
  );
  // Highcharts 12 paints bars as .highcharts-point (path/rect depending on the
  // renderer) — the bare `rect` selector timed out in the baseline run.
  const barFill = await computedColor(page, '#hrChartByUnit .highcharts-point', 'fill');
  recorder.add(
    theme,
    'hr chart bar',
    await measureColorOn(page, 'hr chart bar', barFill, '#hrChartByUnit', { x: 8, y: 8 }),
    3
  );
  await capture(page, theme, 'hr-dashboard', options);

  // ---- 8. Stitch background ---------------------------------------------
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  recorder.add(theme, 'stitch background', await measurePageBackground(page));

  // ---- 9. dark: utilities ------------------------------------------------
  // The custom variant covers all fourteen dark themes (app.css). No app blade
  // currently uses a `dark:` utility, so this row documents the coverage gap
  // with no rendered surface to measure.
  recorder.missing(theme, 'dark: utilities', 'no dark: utility in app blades');
}

/** Markdown table of every measured cell (published on the issue and PR). */
export function toMarkdown(rows: Row[]): string {
  const cell = (value: string | number | null | undefined): string =>
    String(value ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');

  const lines = [
    '| theme | surface | fg | bg | ratio | threshold | method | verdict | note |',
    '|---|---|---|---|---|---|---|---|---|',
    ...rows.map((row) =>
      `| ${cell(row.theme)} | ${cell(row.surface)} | ${cell(row.fg)} | ${cell(row.bg)} | ${cell(
        row.ratio === null ? '' : row.ratio.toFixed(2)
      )} | ${cell(row.threshold)} | ${cell(row.method)} | ${row.verdict} | ${cell(row.note)} |`
    ),
  ];

  return lines.join('\n');
}

/** Per-theme roll-up: counts plus the cells that are not ✅. */
export function toSummary(rows: Row[], themes: string[]): string {
  const lines = [
    '| theme | cells | ✅ | ❌ | 🧱 | n/a | blocking cells |',
    '|---|---|---|---|---|---|---|',
  ];

  for (const theme of themes) {
    const cells = rows.filter((row) => row.theme === theme);
    const count = (verdict: Verdict) => cells.filter((row) => row.verdict === verdict).length;
    const flagged = cells
      .filter((row) => row.verdict === '❌' || row.verdict === '🧱')
      .map((row) => `${row.surface} (${row.ratio === null ? '—' : row.ratio.toFixed(2)})`)
      .join(', ');
    lines.push(
      `| ${theme} | ${cells.length} | ${count('✅')} | ${count('❌')} | ${count('🧱')} | ${count('n/a')} | ${flagged || '—'} |`
    );
  }

  return lines.join('\n');
}
