import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Shared measurement helpers for the theme specs (#1220, #1224).
 *
 * Everything here is measured on the *effective* computed style / rendered
 * pixels — never read from the CSS source. daisyUI themes land in a cascade
 * layer, and the app overrides them from outside the layer, so only
 * `getComputedStyle()` (or a screenshot of what the user actually sees) is a
 * valid witness. This is the method locked in the #1220 verification comment
 * (6362051811) and reused by the #1224 sweep.
 */

/**
 * Every enabled daisyUI theme, in the plugin's own `themeOrder` (#1227).
 * The two families below are the same split as App\Support\ThemeFamilies; a
 * PHP guard (SettingsThemeTest) locks both lists against the `themes:` line in
 * app.css, so this is a copy, never a source of truth.
 */
export const THEMES = [
  'light', 'dark', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'synthwave', 'retro',
  'cyberpunk', 'valentine', 'halloween', 'garden', 'forest', 'aqua', 'lofi', 'pastel',
  'fantasy', 'wireframe', 'black', 'luxury', 'dracula', 'cmyk', 'autumn', 'business',
  'acid', 'lemonade', 'night', 'coffee', 'winter', 'dim', 'nord', 'sunset',
  'caramellatte', 'abyss', 'silk',
];

/** Same split as App\Support\ThemeFamilies (#1227): light 21, dark 14. */
export const LIGHT_THEMES = [
  'light', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'retro', 'cyberpunk',
  'valentine', 'garden', 'lofi', 'pastel', 'fantasy', 'wireframe', 'cmyk', 'autumn',
  'acid', 'lemonade', 'winter', 'nord', 'caramellatte', 'silk',
];
export const DARK_THEMES = [
  'dark', 'synthwave', 'halloween', 'forest', 'aqua', 'black', 'luxury', 'dracula',
  'business', 'night', 'coffee', 'dim', 'sunset', 'abyss',
];

export const CORE_BLOCKING_THEMES = ['light', 'dark', 'synthwave', 'coffee', 'cupcake', 'fantasy', 'luxury'];

/**
 * The themes the mandatory (per-PR) sweep measures, on top of the core seven.
 *
 * Currently EMPTY — and deliberately so. The first post-#1226 CI run with
 * winter/abyss appended (9 themes) measured the blocking sweep at 5.8 min
 * (≈39 s/theme on CI) and pushed the E2E job to the 30-min cap before the
 * suite finished (261 tests green at the kill, zero failures). The gate's
 * budget rule is trim-or-report-only, so winter/abyss live in the report-only
 * 35-theme matrix (where their badge cells stay 🧱 via STRUCTURAL) until
 * either their pairs land or the E2E job finds room. A theme earns a spot
 * here only when the measured budget fits: 7 core + n watch × ~39 s must
 * leave the whole suite under 30 min. Keep sorted.
 */
export const WATCH_THEMES: string[] = [];

export const BLOCKING_THEMES = [...new Set([...CORE_BLOCKING_THEMES, ...WATCH_THEMES])];

/** True when daisyUI declares `color-scheme: dark` for this theme. */
export function isDarkTheme(theme: string): boolean {
  return DARK_THEMES.includes(theme);
}


/** Below `2xl` so the sidebar stays expanded (the #1220 measurement viewport). */
export const VIEWPORT = { width: 1440, height: 960 };

export const WCAG_AA = 4.5;

interface RGB {
  r: number;
  g: number;
  b: number;
}

/**
 * Install the colour maths into the page. Runs before every navigation, so a
 * spec only needs one call in `beforeEach`.
 */
export async function installThemeHelpers(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const toRGB = (s: string): number[] => {
      let L: number;
      let a: number;
      let b: number;

      if (s.startsWith('oklch')) {
        const m = s.match(/oklch\(([\d.]+)%?\s+([\d.-]+)\s+([\d.-]+)(?:\s*\/\s*([\d.]+))?\)/)!;
        L = parseFloat(m[1]) > 1 ? parseFloat(m[1]) / 100 : parseFloat(m[1]);
        const C = parseFloat(m[2]);
        const H = (parseFloat(m[3]) * Math.PI) / 180;
        a = C * Math.cos(H);
        b = C * Math.sin(H);
      } else if (s.startsWith('oklab')) {
        const m = s.match(/oklab\(([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)/)!;
        L = parseFloat(m[1]);
        a = parseFloat(m[2]);
        b = parseFloat(m[3]);
      } else if (s.startsWith('#')) {
        // Chart series colours arrive as hex from Highcharts. Parsing them with
        // the generic digit regex below read `#0ea5e9` as rgb(0,5,9) — near
        // black — which inverted every chart verdict in the baseline run.
        const h = s.slice(1);
        const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
        return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16));
      } else if (s.startsWith('color(')) {
        // Chromium serializes some color-mix() results as color(srgb r g b / a)
        // with 0..1 channels.
        const g = s.match(/[\d.]+/g)!;
        return [Number(g[0]) * 255, Number(g[1]) * 255, Number(g[2]) * 255];
      } else {
        return s.match(/[\d.]+/g)!.slice(0, 3).map(Number);
      }

      const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
      const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
      const s_ = L - 0.0894841775 * a - 1.291485548 * b;
      const l = l_ ** 3;
      const mm = m_ ** 3;
      const ss = s_ ** 3;
      const r = 4.0767416621 * l - 3.3077115913 * mm + 0.2309699292 * ss;
      const g = -1.2684380046 * l + 2.6097574011 * mm - 0.3413193965 * ss;
      const bb = -0.0041960863 * l - 0.7034186147 * mm + 1.707614701 * ss;
      const f = (x: number) => {
        x = Math.max(0, Math.min(1, x));
        return x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055;
      };
      return [f(r), f(g), f(bb)].map((x) => x * 255);
    };

    const alphaOf = (s: string): number => {
      const slash = s.match(/\/\s*([\d.]+)\s*\)/);
      if (slash) return parseFloat(slash[1]);
      const g = s.match(/[\d.]+/g);
      if (s.startsWith('rgb') && g && g.length > 3) return Number(g[3]);
      return 1;
    };

    const lin = (c: number): number => {
      c /= 255;
      return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    };

    const lum = ([r, g, b]: number[]): number => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);

    const ratio = (x: number[], y: number[]): number => {
      let l1 = lum(x);
      let l2 = lum(y);
      if (l1 < l2) [l1, l2] = [l2, l1];
      return (l1 + 0.05) / (l2 + 0.05);
    };

    const over = (fg: number[], bg: number[], a: number): number[] =>
      fg.map((v, i) => v * a + bg[i] * (1 - a));

    (window as unknown as { __theme: unknown }).__theme = { toRGB, alphaOf, lin, lum, ratio, over };
  });
}

/**
 * Pin the decorative blobs to a fixed geometry. Their diameters come from
 * `Math.random()` at load (layouts/app.blade.php), so without this two runs
 * sample a different backdrop and a borderline ratio can flip between green and
 * red. Colours, blur and opacity stay exactly as shipped.
 */
async function freezeBlobs(page: Page): Promise<void> {
  await page.evaluate(() => {
    const sizes = [400, 325, 370];
    document.querySelectorAll<HTMLElement>('.stitch-blob').forEach((el, index) => {
      const size = sizes[index] ?? 350;
      el.style.width = `${size}px`;
      el.style.height = `${size}px`;
    });
  });
}

/**
 * Apply a theme the way the app does (data-theme + MaryUI's `mary-theme` JSON
 * localStorage format **and** its `mary-class` sibling), disable transitions,
 * then wait for the cascade to settle.
 *
 * #1227: `mary-class` is not decorative. MaryUI's `<x-theme-toggle>` writes
 * `light`/`dark` there and the app's boot script copies it onto `<html>`;
 * `.dark .leaflet-*` (the Leaflet invert filter) and the stitch `.dark` rules
 * key off it, not off the theme name. Leaving it unset measured the seven dark
 * themes in a state no user ever sees (no invert, no dark backdrop).
 *
 * The settle loop samples two consecutive equal readings: the first #1220 run
 * measured a half-transitioned colour because a fixed sleep was not enough.
 */
export async function setTheme(page: Page, theme: string): Promise<void> {
  await page.addStyleTag({
    content: '*, *::before, *::after { transition: none !important; animation: none !important; }',
  });
  await page.evaluate(
    ({ t, familyClass }) => {
      document.documentElement.setAttribute('data-theme', t);
      // MaryUI replaces the whole class attribute (lightClass = "light",
      // darkClass = "dark"), so mirror that instead of appending.
      document.documentElement.setAttribute('class', familyClass);
      localStorage.setItem('mary-theme', JSON.stringify(t));
      localStorage.setItem('mary-class', JSON.stringify(familyClass));
    },
    { t: theme, familyClass: isDarkTheme(theme) ? 'dark' : 'light' }
  );

  await freezeBlobs(page);
  await page.waitForTimeout(900);

  const sample = () =>
    page.evaluate(() => {
      const root = getComputedStyle(document.documentElement);
      const body = getComputedStyle(document.body);
      return `${document.documentElement.dataset.theme}|${root.backgroundColor}|${body.color}|${body.backgroundColor}`;
    });

  let previous = '';
  for (let i = 0; i < 20; i++) {
    const current = await sample();
    if (current !== '' && current === previous) return;
    previous = current;
    await page.waitForTimeout(150);
  }
  throw new Error(`theme ${theme} did not settle`);
}

export interface Measurement {
  surface: string;
  fg: string;
  bg: string;
  ratio: number;
  method: 'pixel' | 'computed';
}

/**
 * Contrast between two computed colours (alpha of `fg` composited over `bg`).
 * Used for control indicators (e.g. a toggle knob) that are painted by a
 * pseudo-element rather than a text node.
 */
export async function contrastBetween(
  page: Page,
  surface: string,
  fg: string,
  bg: string
): Promise<Measurement> {
  const ratio = await page.evaluate(
    ({ fg, bg }) => {
      const t = (window as unknown as { __theme: any }).__theme;
      const bgRgb = t.toRGB(bg);
      const fgRgb = t.over(t.toRGB(fg), bgRgb, t.alphaOf(fg));
      return Number(t.ratio(fgRgb, bgRgb).toFixed(2));
    },
    { fg, bg }
  );

  return { surface, fg, bg, ratio, method: 'computed' };
}

interface TextProbe {
  rect: { x: number; y: number; width: number; height: number };
  point: { x: number; y: number };
  color: string;
}

const HIDE_TEXT_STYLE = 'theme-measure-hide-text';

async function hideText(page: Page): Promise<void> {
  await page.evaluate((id) => {
    const style = document.createElement('style');
    style.id = id;
    // Keep every background/border; drop only what paints glyphs, so the
    // screenshot at the text's own coordinates shows the real backdrop.
    style.textContent = `
      html *:not(canvas) { color: transparent !important; -webkit-text-fill-color: transparent !important; text-shadow: none !important; }
      svg, img { visibility: hidden !important; }
    `;
    document.head.appendChild(style);
  }, HIDE_TEXT_STYLE);
}

async function showText(page: Page): Promise<void> {
  await page.evaluate((id) => document.getElementById(id)?.remove(), HIDE_TEXT_STYLE);
}

/** Screenshot `clip` and return the colour of one pixel inside it. */
async function pixelAt(
  page: Page,
  clip: { x: number; y: number; width: number; height: number },
  point: { x: number; y: number }
): Promise<string> {
  const buffer = await page.screenshot({ clip });
  const dataUrl = `data:image/png;base64,${buffer.toString('base64')}`;

  return page.evaluate(
    async ({ url, px, py, box }) => {
      const image = new Image();
      image.src = url;
      await image.decode();

      const canvas = document.createElement('canvas');
      canvas.width = image.width;
      canvas.height = image.height;
      const ctx = canvas.getContext('2d')!;
      ctx.drawImage(image, 0, 0);

      const x = Math.max(0, Math.min(image.width - 1, Math.round(px - box.x)));
      const y = Math.max(0, Math.min(image.height - 1, Math.round(py - box.y)));
      const d = ctx.getImageData(x, y, 1, 1).data;
      return `rgb(${d[0]}, ${d[1]}, ${d[2]})`;
    },
    { url: dataUrl, px: point.x, py: point.y, box: clip }
  );
}

const PAINT_FILTER_ATTR = 'data-theme-measure-filter';

/**
 * Suspend `filter` on the measured node and its ancestors for one sample.
 *
 * `filter` is painted after computed style: `.dark .leaflet-control-*` inverts
 * the Leaflet controls (app.css), so the computed text colour is pre-filter
 * while the screenshot is post-filter. Comparing them reports 1.00:1 for a
 * control that actually renders white-on-black (21:1). Invert maps the
 * achromatic control pair onto its own inverse, so measuring the unfiltered
 * pair yields the same ratio as the user sees.
 */
async function suspendPaintFilters(locator: Locator): Promise<void> {
  await locator.evaluate((node, attr) => {
    for (let el: Element | null = node; el; el = el.parentElement) {
      if (getComputedStyle(el).filter !== 'none') {
        (el as HTMLElement).setAttribute(attr, (el as HTMLElement).style.filter);
        (el as HTMLElement).style.setProperty('filter', 'none', 'important');
      }
    }
  }, PAINT_FILTER_ATTR);
}

async function resumePaintFilters(page: Page): Promise<void> {
  await page.evaluate((attr) => {
    document.querySelectorAll<HTMLElement>(`[${attr}]`).forEach((el) => {
      const previous = el.getAttribute(attr) ?? '';
      el.style.removeProperty('filter');
      if (previous) el.style.filter = previous;
      el.removeAttribute(attr);
    });
  }, PAINT_FILTER_ATTR);
}

/**
 * Measure text contrast by pixel-sampling the rendered backdrop at the text's
 * own coordinates (the primary method locked by #1224 for glass/gradient
 * surfaces). The element's text is hidden for the screenshot only.
 */
export async function measureText(
  page: Page,
  surface: string,
  selector: string,
  options: { colorProperty?: 'color' | 'fill' } = {}
): Promise<Measurement> {
  const locator = page.locator(selector).first();
  await expect(locator).toBeVisible();
  await locator.scrollIntoViewIfNeeded();

  const probe = await locator.evaluate(
    (node, colorProperty): TextProbe => {
      const el = node as HTMLElement | SVGElement;
      const rect = el.getBoundingClientRect();

      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let textNode: Text | null = null;
      while (walker.nextNode()) {
        const candidate = walker.currentNode as Text;
        if (candidate.nodeValue && candidate.nodeValue.trim()) {
          textNode = candidate;
          break;
        }
      }

      let textRect = rect;
      if (textNode) {
        const range = document.createRange();
        range.selectNodeContents(textNode);
        const r = range.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) textRect = r;
      }

      const cs = getComputedStyle(el);
      const color = colorProperty === 'fill' ? cs.fill : cs.color;

      return {
        rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
        point: { x: textRect.x + textRect.width / 2, y: textRect.y + textRect.height / 2 },
        color,
      };
    },
    options.colorProperty ?? 'color'
  );

  const viewport = page.viewportSize()!;
  const clip = {
    x: Math.max(0, Math.floor(probe.rect.x)),
    y: Math.max(0, Math.floor(probe.rect.y)),
    width: 0,
    height: 0,
  };
  clip.width = Math.max(1, Math.min(viewport.width - clip.x, Math.ceil(probe.rect.width)));
  clip.height = Math.max(1, Math.min(viewport.height - clip.y, Math.ceil(probe.rect.height)));

  await hideText(page);
  await suspendPaintFilters(locator);
  let bg: string;
  try {
    bg = await pixelAt(page, clip, probe.point);
  } finally {
    await resumePaintFilters(page);
    await showText(page);
  }

  const ratio = await page.evaluate(
    ({ fg, bg }) => {
      const t = (window as unknown as { __theme: any }).__theme;
      const bgRgb = t.toRGB(bg);
      const fgRgb = t.over(t.toRGB(fg), bgRgb, t.alphaOf(fg));
      return Number(t.ratio(fgRgb, bgRgb).toFixed(2));
    },
    { fg: probe.color, bg }
  );

  return { surface, fg: probe.color, bg, ratio, method: 'pixel' };
}

/**
 * Contrast between an explicit colour (e.g. a Highcharts series colour) and the
 * backdrop behind a given element. Used where there is no text node to anchor
 * on (chart bars, toggle tracks, background washes).
 */
export async function measureColorOn(
  page: Page,
  surface: string,
  color: string,
  selector: string,
  point?: { x: number; y: number }
): Promise<Measurement> {
  const locator = page.locator(selector).first();
  await expect(locator).toBeVisible();
  await locator.scrollIntoViewIfNeeded();

  const rect = await locator.evaluate((node) => {
    const r = (node as HTMLElement).getBoundingClientRect();
    return { x: r.x, y: r.y, width: r.width, height: r.height };
  });

  const viewport = page.viewportSize()!;
  const clip = {
    x: Math.max(0, Math.floor(rect.x)),
    y: Math.max(0, Math.floor(rect.y)),
    width: Math.max(1, Math.min(viewport.width - Math.max(0, Math.floor(rect.x)), Math.ceil(rect.width))),
    height: Math.max(1, Math.min(viewport.height - Math.max(0, Math.floor(rect.y)), Math.ceil(rect.height))),
  };

  const target = point
    ? { x: rect.x + point.x, y: rect.y + point.y }
    : { x: rect.x + rect.width - 8, y: rect.y + 8 };

  await hideText(page);
  let bg: string;
  try {
    bg = await pixelAt(page, clip, target);
  } finally {
    await showText(page);
  }

  const ratio = await page.evaluate(
    ({ fg, bg }) => {
      const t = (window as unknown as { __theme: any }).__theme;
      const bgRgb = t.toRGB(bg);
      const fgRgb = t.over(t.toRGB(fg), bgRgb, t.alphaOf(fg));
      return Number(t.ratio(fgRgb, bgRgb).toFixed(2));
    },
    { fg: color, bg }
  );

  return { surface, fg: color, bg, ratio, method: 'pixel' };
}

/**
 * Sample the page's own backdrop (the stitch gradient) with all content hidden,
 * then contrast the body text colour against it.
 */
export async function measurePageBackground(page: Page): Promise<Measurement> {
  const color = await page.evaluate(() => getComputedStyle(document.body).color);

  await page.evaluate(() => {
    const keep = ['.stitch-bg-fixed', '.stitch-blobs'];
    Array.from(document.body.children).forEach((child) => {
      const el = child as HTMLElement;
      if (keep.some((selector) => el.matches(selector))) return;
      el.style.visibility = 'hidden';
    });
    const style = document.createElement('style');
    style.id = 'theme-measure-bg';
    style.textContent = 'html * { color: transparent !important; text-shadow: none !important; }';
    document.head.appendChild(style);
  });

  let sample: string;
  try {
    const viewport = page.viewportSize()!;
    const buffer = await page.screenshot({
      clip: { x: 0, y: 0, width: viewport.width, height: viewport.height },
    });
    const dataUrl = `data:image/png;base64,${buffer.toString('base64')}`;
    sample = await page.evaluate(
      async ({ url, x, y }) => {
        const image = new Image();
        image.src = url;
        await image.decode();
        const canvas = document.createElement('canvas');
        canvas.width = image.width;
        canvas.height = image.height;
        const ctx = canvas.getContext('2d')!;
        ctx.drawImage(image, 0, 0);
        const d = ctx.getImageData(x, y, 1, 1).data;
        return `rgb(${d[0]}, ${d[1]}, ${d[2]})`;
      },
      { url: dataUrl, x: Math.floor(viewport.width / 2), y: 40 }
    );
  } finally {
    await page.evaluate(() => {
      document.getElementById('theme-measure-bg')?.remove();
      Array.from(document.body.children).forEach((child) => {
        (child as HTMLElement).style.visibility = '';
      });
    });
  }

  const ratio = await page.evaluate(
    ({ fg, bg }) => {
      const t = (window as unknown as { __theme: any }).__theme;
      const bgRgb = t.toRGB(bg);
      return Number(t.ratio(t.toRGB(fg), bgRgb).toFixed(2));
    },
    { fg: color, bg: sample }
  );

  return { surface: 'stitch background', fg: color, bg: sample, ratio, method: 'pixel' };
}
