import { test, expect, login } from '../shared/fixtures';
import * as fs from 'fs';
import * as path from 'path';
import { installThemeHelpers, setTheme, BLOCKING_THEMES, VIEWPORT } from './theme-helpers';

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
 *
 * #1529 adds the hover state: MaryUI's `hover:text-inherit` on every anchor and
 * summary repaints the active label with the page text colour while the
 * background stays neutral (19 of 35 themes under 4.5:1). The contract is not
 * just "hover clears AA" but "hover IS the resting pair", so a fix that swaps
 * in a different readable pair still fails.
 */

// Same viewport the bug was measured at: below `2xl` so the sidebar is expanded.
test.use({ viewport: VIEWPORT });

const CAPTURE_DIR = process.env.E2E_CAPTURE_DIR;

const ACTIVE = '.drawer-side a.mary-active-menu[aria-current="page"]';

/**
 * The header of the submenu that actually CONTAINS the active item.
 *
 * #1529: the old `.drawer-side details[open] > summary` took the *first* open
 * submenu. On `/kargozini/persons` that is usually the active one, but the
 * moment a second submenu is opened it becomes an inactive header with a
 * different pair (light read 17.73 instead of 15.71).
 */
const ACTIVE_SUMMARY =
  '.drawer-side details:has(> ul > li > a.mary-active-menu) > summary';

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
  return page.evaluate(
    ({ activeSelector, summarySelector }) => {
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

      const active = document.querySelector(activeSelector) as HTMLElement | null;
      const header = document.querySelector(summarySelector) as HTMLElement | null;
      if (!active || !header) throw new Error('active sidebar item or active submenu header not found');

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
    },
    { activeSelector: ACTIVE, summarySelector: ACTIVE_SUMMARY }
  );
}

/**
 * Menu colours animate with a delay, and the first #1220 run sampled a
 * half-transitioned colour (active bg settled while the header still carried
 * the previous theme's colour). Sample until two consecutive readings agree.
 */
async function waitForMenuSettled(page: import('@playwright/test').Page) {
  const sample = () =>
    page.evaluate(
      ({ activeSelector, summarySelector }) => {
        const active = document.querySelector(activeSelector) as HTMLElement | null;
        const header = document.querySelector(summarySelector) as HTMLElement | null;
        if (!active || !header) return '';
        const a = getComputedStyle(active);
        const h = getComputedStyle(header);
        return `${a.color}|${a.backgroundColor}|${h.color}|${h.backgroundColor}`;
      },
      { activeSelector: ACTIVE, summarySelector: ACTIVE_SUMMARY }
    );

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
    await expect(page.locator(ACTIVE)).toHaveCount(1);
    await expect(page.locator(ACTIVE_SUMMARY)).toHaveCount(1);
  });

  for (const theme of BLOCKING_THEMES) {
    test(`${theme}: active item is the native neutral pair and clears WCAG AA`, async ({ page }) => {
      await setTheme(page, theme);
      await waitForMenuSettled(page);

      const rest = await measure(page);

      // #1529: MaryUI puts `hover:text-inherit` on every menu anchor and
      // summary, so hovering used to paint the label with the page text colour
      // on the neutral background (light 1.12:1, fantasy 1.00:1). Measure the
      // active <a> and — one at a time, the pointer can only be over one — the
      // header of the submenu that contains it.
      await page.hover(ACTIVE);
      await waitForMenuSettled(page);
      const activeHover = await measure(page);

      await page.hover(ACTIVE_SUMMARY);
      await waitForMenuSettled(page);
      const headerHover = await measure(page);

      // Evidence first: log and capture even when an assertion below fails, so
      // a red run still produces the before/after table and screenshots.
      // eslint-disable-next-line no-console
      console.log(
        `[#1220] ${theme}: active ${rest.active.color} on ${rest.active.background} = ${rest.active.ratio}:1; ` +
          `header ${rest.header.color} on ${rest.header.background} = ${rest.header.ratio}:1`
      );
      // eslint-disable-next-line no-console
      console.log(
        `[#1529] ${theme}: active hover ${activeHover.active.color} on ${activeHover.active.background} ` +
          `= ${activeHover.active.ratio}:1; header hover ${headerHover.header.color} on ` +
          `${headerHover.header.background} = ${headerHover.header.ratio}:1`
      );

      if (CAPTURE_DIR) {
        fs.mkdirSync(CAPTURE_DIR, { recursive: true });
        await page.locator('.drawer-side').screenshot({ path: path.join(CAPTURE_DIR, `${theme}.png`) });
      }

      // Option C contract: both halves of the pair come from daisyUI's own
      // neutral family (not neutral-content on base-300, not base-content).
      expect(rest.active.color, `${theme} active color must be --color-neutral-content`).toBe(
        rest.active.expectedColor
      );
      expect(rest.active.background, `${theme} active background must be --color-neutral`).toBe(
        rest.active.expectedBackground
      );

      expect(rest.active.ratio, `${theme} active item contrast ${rest.active.ratio}:1 must be >= 4.5`)
        .toBeGreaterThanOrEqual(4.5);
      // Gate contract item 2: the active submenu header may keep its current
      // pair or inherit the neutral one — either way it must stay readable.
      expect(rest.header.ratio, `${theme} submenu header contrast ${rest.header.ratio}:1 must be >= 4.5`)
        .toBeGreaterThanOrEqual(4.5);

      // #1529 contract: hover must reproduce the resting pair *exactly*, not
      // merely clear AA against some other background. The identity assertions
      // are what reject a "fix" that swaps in a different readable pair.
      expect(activeHover.active.color, `${theme} active hover color must equal its resting color`).toBe(
        rest.active.color
      );
      expect(
        activeHover.active.background,
        `${theme} active hover background must equal its resting background`
      ).toBe(rest.active.background);
      expect(
        activeHover.active.ratio,
        `${theme} active item hover contrast ${activeHover.active.ratio}:1 must be >= 4.5`
      ).toBeGreaterThanOrEqual(4.5);

      expect(headerHover.header.color, `${theme} submenu header hover color must equal its resting color`).toBe(
        rest.header.color
      );
      expect(
        headerHover.header.background,
        `${theme} submenu header hover background must equal its resting background`
      ).toBe(rest.header.background);
      expect(
        headerHover.header.ratio,
        `${theme} submenu header hover contrast ${headerHover.header.ratio}:1 must be >= 4.5`
      ).toBeGreaterThanOrEqual(4.5);
    });
  }
});
