import { test, expect, login } from '../shared/fixtures';
import * as fs from 'fs';
import * as path from 'path';

/**
 * #1220 — sidebar active item must keep WCAG AA contrast in every theme.
 *
 * Root cause (measured on beta@0ba28f5c): daisyUI's `[aria-current="page"]`
 * rule paints the active label with `--menu-active-fg`
 * (= `--color-neutral-content`), while MaryUI's `bg-base-300` paints the
 * background. They are two unrelated token families and collide in the three
 * light themes: light 1.09:1, cupcake 1.01:1, fantasy 1.01:1 (fantasy is the
 * app default). Owner decision (issue comment 6362394219, option C): make the
 * active item use daisyUI's native `neutral` / `neutral-content` pair in every
 * theme, so foreground and background come from the same family.
 *
 * This spec is the only real regression guard: the values are *computed* CSS,
 * which a Pest test cannot see. It pins both the pair (so a later "fix" cannot
 * silently swap the identity back to base-*) and the WCAG AA ratio.
 */

const THEMES = ['light', 'dark', 'synthwave', 'coffee', 'cupcake', 'fantasy', 'luxury'];

// Same viewport the bug was measured at: below `2xl` so the sidebar is expanded.
test.use({ viewport: { width: 1440, height: 960 } });

const CAPTURE_DIR = process.env.E2E_CAPTURE_DIR;

interface PairMeasurement {
  color: string;
  background: string;
  ratio: number;
  expectedColor: string;
  expectedBackground: string;
}

/**
 * Measure the active item and the open-submenu header in the CURRENT theme.
 *
 * Colors are compared against live probes bound to the daisyUI tokens, not
 * against hard-coded strings: `--color-neutral` / `--color-neutral-content`
 * differ per theme and Chromium serializes them as oklch(). The probe keeps
 * the assertion meaningful without duplicating every theme's palette here.
 */
async function measure(page: import('@playwright/test').Page) {
  return page.evaluate(() => {
    const toRGB = (s: string): number[] => {
      let L: number, a: number, b: number;
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
    const alphaOf = (s: string) => {
      const m = s.match(/\/\s*([\d.]+)\)/);
      if (m) return parseFloat(m[1]);
      const g = s.match(/[\d.]+/g);
      if (s.startsWith('rgb') && g && g.length > 3) return Number(g[3]);
      return 1;
    };
    const lin = (c: number) => {
      c /= 255;
      return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    };
    const lum = ([r, g, b]: number[]) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    const ratio = (x: number[], y: number[]) => {
      let l1 = lum(x);
      let l2 = lum(y);
      if (l1 < l2) [l1, l2] = [l2, l1];
      return (l1 + 0.05) / (l2 + 0.05);
    };
    const over = (fg: number[], bg: number[], a: number) =>
      fg.map((v, i) => v * a + bg[i] * (1 - a));

    // Resolve the expected pair from the live tokens (current theme).
    const probe = document.createElement('div');
    probe.style.color = 'var(--color-neutral-content)';
    probe.style.backgroundColor = 'var(--color-neutral)';
    document.body.appendChild(probe);
    const probeStyle = getComputedStyle(probe);
    const expectedColor = probeStyle.color;
    const expectedBackground = probeStyle.backgroundColor;
    probe.remove();

    const active = document.querySelector(
      '.drawer-side a.mary-active-menu[aria-current="page"]'
    ) as HTMLElement | null;
    const header = document.querySelector(
      '.drawer-side details[open] > summary'
    ) as HTMLElement | null;
    if (!active || !header) throw new Error('active sidebar item or open submenu header not found');

    const htmlBg = toRGB(getComputedStyle(document.documentElement).backgroundColor);
    const effectiveBg = (el: HTMLElement) => {
      const s = getComputedStyle(el).backgroundColor;
      return over(toRGB(s), htmlBg, alphaOf(s));
    };

    const activeStyle = getComputedStyle(active);
    const headerStyle = getComputedStyle(header);

    return {
      active: {
        color: activeStyle.color,
        background: activeStyle.backgroundColor,
        ratio: Number(ratio(toRGB(activeStyle.color), effectiveBg(active)).toFixed(2)),
        expectedColor,
        expectedBackground,
      },
      header: {
        color: headerStyle.color,
        background: headerStyle.backgroundColor,
        ratio: Number(ratio(toRGB(headerStyle.color), effectiveBg(header)).toFixed(2)),
      },
    };
  });
}

async function setTheme(page: import('@playwright/test').Page, theme: string) {
  await page.addStyleTag({
    content: '*, *::before, *::after { transition: none !important; animation: none !important; }',
  });
  await page.evaluate((t) => {
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('mary-theme', t);
  }, theme);

  // The theme switch animates `color` on menu elements with a delay; sampling
  // during that delay reads the previous theme's value twice and looks
  // "stable". Let the longest transition finish before taking samples.
  await page.waitForTimeout(900);

  // Wait until both the active item and the open header are stable across two
  // samples. The first full run sampled a half-transitioned color: the active
  // bg had settled while the header still carried the previous theme's color,
  // so a fixed sleep (and a sample of only the active item) is not enough.
  const sample = () =>
    page.evaluate(() => {
      const active = document.querySelector('.drawer-side a.mary-active-menu') as HTMLElement | null;
      const header = document.querySelector('.drawer-side details[open] > summary') as HTMLElement | null;
      if (!active || !header) return '';
      const a = getComputedStyle(active);
      const h = getComputedStyle(header);
      return `${a.color}|${a.backgroundColor}|${h.color}|${h.backgroundColor}`;
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

test.describe('sidebar active item contrast', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    // A submenu child is active here: exercises both the active <a> and the
    // open <summary> header (the exact surface from the issue screenshot).
    await page.goto('/kargozini/persons');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.drawer-side a.mary-active-menu[aria-current="page"]')).toHaveCount(1);
  });

  for (const theme of THEMES) {
    test(`${theme}: active item is the native neutral pair and clears WCAG AA`, async ({ page }) => {
      await setTheme(page, theme);

      const m = await measure(page);

      // Evidence first: log and capture even when an assertion below fails, so
      // a red run still produces the before/after table and screenshots.
      // eslint-disable-next-line no-console
      console.log(
        `[#1220] ${theme}: active ${m.active.color} on ${m.active.background} = ${m.active.ratio}:1; ` +
          `header ${m.header.color} on ${m.header.background} = ${m.header.ratio}:1`
      );

      if (CAPTURE_DIR) {
        fs.mkdirSync(CAPTURE_DIR, { recursive: true });
        await page.locator('.drawer-side').screenshot({ path: path.join(CAPTURE_DIR, `${theme}.png`) });
      }

      // Option C contract: both halves of the pair come from daisyUI's own
      // neutral family (not neutral-content on base-300, not base-content).
      expect(m.active.color, `${theme} active color must be --color-neutral-content`).toBe(
        m.active.expectedColor
      );
      expect(m.active.background, `${theme} active background must be --color-neutral`).toBe(
        m.active.expectedBackground
      );

      expect(m.active.ratio, `${theme} active item contrast ${m.active.ratio}:1 must be >= 4.5`)
        .toBeGreaterThanOrEqual(4.5);
      // Gate contract item 2: the open submenu header may keep its current
      // pair or inherit the neutral one — either way it must stay readable.
      expect(m.header.ratio, `${theme} submenu header contrast ${m.header.ratio}:1 must be >= 4.5`)
        .toBeGreaterThanOrEqual(4.5);
    });
  }
});
