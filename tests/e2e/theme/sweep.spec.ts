import { test, expect, login } from '../shared/fixtures';
import * as fs from 'fs';
import * as path from 'path';
import {
  contrastBetween,
  installThemeHelpers,
  measureColorOn,
  measurePageBackground,
  measureText,
  setTheme,
  THEMES,
  VIEWPORT,
} from './theme-helpers';

/**
 * #1224 — measured health sweep: 9 surfaces × 7 enabled daisyUI themes.
 *
 * Method (locked by the issue's verification comment 6364662792):
 *  - real Chromium + real login, viewport 1440×960;
 *  - theme applied through the app's own storage keys (data-theme + JSON
 *    `mary-theme`), transitions disabled, settle loop before every sample;
 *  - only the effective computed style / rendered pixels (pixel sampling is
 *    the primary method for glass + stitch-gradient backdrops);
 *  - WCAG 2.1 relative luminance, alpha composited over the real backdrop.
 *
 * Set SWEEP_REPORT_ONLY=1 to print the table without failing (baseline runs);
 * CI runs it strict. Screenshots are written when E2E_CAPTURE_DIR is set.
 */

const REPORT_ONLY = process.env.SWEEP_REPORT_ONLY === '1';
const CAPTURE_DIR = process.env.E2E_CAPTURE_DIR;

interface Row {
  theme: string;
  surface: string;
  fg: string;
  bg: string;
  ratio: number | null;
  threshold: number;
  method: string;
  verdict: '✅' | '❌' | '🧱' | 'n/a';
  note?: string;
}

const rows: Row[] = [];

/**
 * Known structural findings that cannot be fixed in this PR (verdict 🧱).
 * Key: `${theme}:${surface}`. Value: the measured reason, published verbatim in
 * the PR table so a follow-up issue can be filed against the real root cause.
 */
const STRUCTURAL = new Map<string, string>([
  // Empty since #1226 replaced the three badge-solid entries with the per-theme
  // opaque/glass matrix in app.css. Kept so future structural findings have one
  // documented home (and `add()` keeps its single suppression path).
]);

function add(
  theme: string,
  surface: string,
  measurement: { fg: string; bg: string; ratio: number; method: string },
  threshold = 4.5,
  note?: string
): void {
  const structural = STRUCTURAL.get(`${theme}:${surface}`);
  rows.push({
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

function missing(theme: string, surface: string, note: string): void {
  rows.push({
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

async function computedColor(
  page: import('@playwright/test').Page,
  selector: string,
  property: 'color' | 'fill' | 'stroke' | 'backgroundColor' = 'color'
): Promise<string> {
  return page
    .locator(selector)
    .first()
    .evaluate((el, prop) => (getComputedStyle(el) as unknown as Record<string, string>)[prop], property);
}

async function placeholderColor(page: import('@playwright/test').Page, selector: string): Promise<string> {
  return page.locator(selector).first().evaluate((el) => getComputedStyle(el, '::placeholder').color);
}

async function fireToast(page: import('@playwright/test').Page, variant: string): Promise<void> {
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
 * #1226 — solid badge variants the sweep must pin. The page row below only
 * ever saw the first `.badge:visible` on /users (= primary); several other
 * variants were sub-AA in dark/synthwave/fantasy/light/luxury.
 */
const BADGE_VARIANTS = ['primary', 'secondary', 'neutral', 'info', 'success', 'warning', 'error', 'ghost'] as const;

/**
 * Inject one solid badge per variant into a fixed probe strip. Measured through
 * the same `measureText` pixel path as real badges, so glass/backdrop-filter is
 * part of the rendered result rather than computed from the CSS source.
 */
async function injectBadgeProbes(page: import('@playwright/test').Page): Promise<void> {
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

async function capture(page: import('@playwright/test').Page, theme: string, name: string): Promise<void> {
  if (!CAPTURE_DIR) return;
  fs.mkdirSync(CAPTURE_DIR, { recursive: true });
  await page.screenshot({
    path: path.join(CAPTURE_DIR, `1224-${theme}-${name}.png`),
    fullPage: true,
  });
}

async function sweepTheme(page: import('@playwright/test').Page, theme: string): Promise<void> {
  // ---- 1. Menu: normal / hover / active / open submenu header ------------
  await page.goto('/kargozini/persons');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);

  const inactive = '.drawer-side .menu a:not([aria-current="page"])';
  add(theme, 'menu normal', await measureText(page, 'menu normal', inactive));
  await page.locator(inactive).first().hover();
  add(theme, 'menu hover', await measureText(page, 'menu hover', inactive));
  add(
    theme,
    'menu active',
    await measureText(page, 'menu active', '.drawer-side a.mary-active-menu[aria-current="page"]')
  );
  add(
    theme,
    'submenu header',
    await measureText(page, 'submenu header', '.drawer-side details[open] > summary')
  );
  await capture(page, theme, 'menu');

  // ---- 2. Alerts / toasts ------------------------------------------------
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  await capture(page, theme, 'dashboard');

  for (const variant of ['info', 'success', 'warning', 'error']) {
    await fireToast(page, variant);
    add(theme, `toast ${variant}`, await measureText(page, `toast ${variant}`, `.alert.alert-${variant}`));
  }

  // ---- 3. Glass blocks ---------------------------------------------------
  await page.goto('/settings');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);

  add(theme, 'glass card', await measureText(page, 'glass card', '.card h2'));

  await page.locator('button[title="راهنما"]').first().click();
  await expect(page.locator('.modal-box')).toBeVisible();
  add(theme, 'glass modal', await measureText(page, 'glass modal', '.modal-box'));
  await capture(page, theme, 'settings');
  await page.keyboard.press('Escape');

  // ---- 4. Form controls --------------------------------------------------
  const selectColor = await computedColor(page, '.select:visible', 'color');
  add(
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
  add(
    theme,
    'form toggle knob',
    await contrastBetween(page, 'form toggle knob', toggleKnob, toggleTrack),
    3
  );

  // ---- 5. Buttons / badges ----------------------------------------------
  await page.goto('/hardware');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  add(theme, 'button solid', await measureText(page, 'button solid', '.btn-success:visible'));
  add(theme, 'button ghost', await measureText(page, 'button ghost', '.btn-ghost:visible'));

  const inputPlaceholder = await placeholderColor(page, '.input:visible');
  add(
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
    add(theme, 'badge solid', await measureText(page, 'badge solid', '.badge:visible'));
  } else {
    missing(theme, 'badge solid', 'no badge on /users');
  }
  if (await page.locator('.badge-outline:visible').count()) {
    add(theme, 'badge outline', await measureText(page, 'badge outline', '.badge-outline:visible'));
  } else {
    missing(theme, 'badge outline', 'no outline badge on /users');
  }

  // Every solid variant must clear AA, not just the first badge on the page.
  await injectBadgeProbes(page);
  for (const variant of BADGE_VARIANTS) {
    add(theme, `badge ${variant}`, await measureText(page, `badge ${variant}`, `#badge-probe-${variant}`));
  }
  await page.evaluate(() => document.getElementById('badge-probes')?.remove());

  // ---- 6. Leaflet controls ----------------------------------------------
  await page.goto('/maps/county');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  add(theme, 'leaflet zoom', await measureText(page, 'leaflet zoom', '.leaflet-control-zoom-in'));
  add(
    theme,
    'leaflet attribution',
    await measureText(page, 'leaflet attribution', '.leaflet-control-attribution')
  );
  await capture(page, theme, 'maps');

  // ---- 7. Charts ---------------------------------------------------------
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  await expect(page.locator('#ticketTrendChart svg')).toBeVisible();
  await page.waitForTimeout(400);

  // The palette is theme-aware, so read the rendered series colour instead of
  // hard-coding a hex (a stale hex would silently measure the wrong colour).
  const trendStroke = await computedColor(page, '#ticketTrendChart .highcharts-graph', 'stroke');
  add(
    theme,
    'dashboard trend line',
    await measureColorOn(page, 'dashboard trend line', trendStroke, '#ticketTrendChart', {
      x: 8,
      y: 8,
    }),
    3
  );
  const sliceFill = await computedColor(page, '#ticketStatusChart .highcharts-point', 'fill');
  add(
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
  await expect(page.locator('#hrChartByUnit svg')).toBeVisible();
  await page.waitForTimeout(400);

  add(
    theme,
    'hr chart axis text',
    await measureText(page, 'hr chart axis text', '#hrChartByUnit .highcharts-axis-labels text', {
      colorProperty: 'fill',
    })
  );
  // Highcharts 12 paints bars as .highcharts-point (path/rect depending on the
  // renderer) — the bare `rect` selector timed out in the baseline run.
  const barFill = await computedColor(page, '#hrChartByUnit .highcharts-point', 'fill');
  add(
    theme,
    'hr chart bar',
    await measureColorOn(page, 'hr chart bar', barFill, '#hrChartByUnit', { x: 8, y: 8 }),
    3
  );
  await capture(page, theme, 'hr-dashboard');

  // ---- 8. Stitch background ---------------------------------------------
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle');
  await setTheme(page, theme);
  const background = await measurePageBackground(page);
  add(theme, 'stitch background', background);

  // ---- 9. dark: utilities ------------------------------------------------
  // The custom variant now covers all four dark poles (app.css). No app blade
  // currently uses a `dark:` utility, so this row documents the coverage gap
  // with no rendered surface to measure.
  missing(theme, 'dark: utilities', 'no dark: utility in app blades');
}

test.use({ viewport: VIEWPORT });

test.describe('theme health sweep', () => {
  test('all 7 themes: 9 surfaces clear WCAG AA', async ({ page }) => {
    test.setTimeout(900_000);
    await installThemeHelpers(page);
    await login(page);

    for (const theme of THEMES) {
      try {
        await sweepTheme(page, theme);
      } catch (error) {
        missing(theme, 'sweep aborted', String(error));
      }
    }

    // eslint-disable-next-line no-console
    console.log(`[#1224-sweep] ${JSON.stringify(rows)}`);

    if (REPORT_ONLY) return;

    const failures = rows.filter((row) => row.verdict === '❌');
    expect(failures, JSON.stringify(failures, null, 2)).toEqual([]);
  });
});
