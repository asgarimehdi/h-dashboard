import { test, expect, login } from '../shared/fixtures';
import * as fs from 'fs';
import * as path from 'path';
import { installThemeHelpers, setTheme, BLOCKING_THEMES as THEMES, VIEWPORT } from './theme-helpers';

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

// Same viewport the bug was measured at: below `2xl` so the sidebar is expanded.
test.use({ viewport: VIEWPORT });

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
    const t = (window as unknown as { __theme: any }).__theme;
    const toRGB = t.toRGB as (s: string) => number[];
    const alphaOf = t.alphaOf as (s: string) => number;
    const ratio = t.ratio as (x: number[], y: number[]) => number;
    const over = t.over as (fg: number[], bg: number[], a: number) => number[];

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

/**
 * Menu colours animate with a delay, and the first #1220 run sampled a
 * half-transitioned colour (active bg settled while the header still carried
 * the previous theme's colour). Sample until two consecutive readings agree.
 */
async function waitForMenuSettled(page: import('@playwright/test').Page) {
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
  throw new Error('sidebar colours did not settle');
}

test.describe('sidebar active item contrast', () => {
  test.beforeEach(async ({ page }) => {
    await installThemeHelpers(page);
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
      await waitForMenuSettled(page);

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
