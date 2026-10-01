import { test, expect, login } from '../shared/fixtures';

/**
 * Toast / alert readability across every enabled DaisyUI theme.
 *
 * The global glass rule in resources/css/app.css replaces the alert variant
 * background (alert-warning, alert-error, ...) with a base-100 glass panel,
 * but text used to keep --color-*-content — a color DaisyUI designs FOR the
 * bright variant background. Measured against base-100 that is 1.01:1
 * (error), 1.58:1 (success), 1.71:1 (warning) in the dark theme: unreadable.
 * Light themes read fine against white, which is why only dark mode showed it.
 *
 * The fix anchors text to --color-base-content with a 30% variant tint.
 * Every theme x every variant must clear WCAG AA (>= 4.5:1).
 */

const THEMES = ['light', 'dark', 'synthwave', 'coffee', 'cupcake', 'fantasy', 'luxury'];
const VARIANTS = ['info', 'success', 'warning', 'error'];

interface ContrastResult {
  variant: string;
  theme: string;
  before: number;
  after: number;
}

/** Trigger a real MaryUI toast (window.toast is installed by <x-toast />). */
async function fireToast(page: import('@playwright/test').Page, variant: string) {
  await page.evaluate((v) => {
    const w = window as unknown as { toast: (p: unknown) => void };
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
}

/**
 * Measure WCAG contrast of the toast text against its rendered background.
 * Chromium serializes computed colors as oklch()/oklab(), so the conversion
 * handles those forms too. `simulateOld` re-applies the pre-fix color
 * (--color-<variant>-content) so the test proves the fix changed the outcome.
 */
async function measureContrast(
  page: import('@playwright/test').Page,
  variant: string,
  theme: string,
  simulateOld: boolean
): Promise<number> {
  return page.evaluate(
    ([v, th, old]) => {
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

      const el = document.querySelector(`.alert.alert-${v}`) as HTMLElement;
      const cs = getComputedStyle(el);
      const htmlBg = toRGB(getComputedStyle(document.documentElement).backgroundColor);
      const bg = over(toRGB(cs.backgroundColor), htmlBg, alphaOf(cs.backgroundColor));
      let text: number[];
      if (old) {
        el.style.setProperty('color', `var(--color-${v}-content)`, 'important');
        text = toRGB(getComputedStyle(el).color);
        el.style.removeProperty('color');
      } else {
        text = toRGB(cs.color);
      }
      return Number(ratio(text, bg).toFixed(2));
    },
    [variant, theme, simulateOld] as const
  );
}

test.describe('toast contrast across themes', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/profile');
    await page.waitForLoadState('networkidle');
  });

  for (const theme of THEMES) {
    test(`${theme}: toast text clears WCAG AA on every variant`, async ({ page }) => {
      await page.evaluate((th) => document.documentElement.setAttribute('data-theme', th), theme);

      const results: ContrastResult[] = [];
      for (const variant of VARIANTS) {
        await fireToast(page, variant);
        await expect(page.locator(`.alert.alert-${variant}`)).toBeVisible();
        results.push({
          theme,
          variant,
          before: await measureContrast(page, variant, theme, true),
          after: await measureContrast(page, variant, theme, false),
        });
      }

      const dark = ['dark', 'synthwave', 'coffee', 'luxury'];
      for (const r of results) {
        if (dark.includes(theme)) {
          // documents the original bug: pre-fix text was unreadable on dark themes
          expect(r.before, `${theme}/${r.variant} pre-fix contrast`).toBeLessThan(3);
        }
        expect(
          r.after,
          `${theme}/${r.variant} contrast ${r.after}:1 must be >= 4.5 (was ${r.before}:1)`
        ).toBeGreaterThanOrEqual(4.5);
      }
    });
  }
});
