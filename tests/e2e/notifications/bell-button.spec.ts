// Header icons share one hover language: no background at rest, and the same
// faint primary tint on hover as the help (?) button. Regression tests for the
// bell's sticky grey hover box (the `btn` base class, #1396) and for the
// bell/theme toggle having no hover feedback at all.
import { test, expect, login } from '../shared/fixtures';

/** True when a computed background-color is fully transparent (rgba/oklab/oklch). */
function isTransparent(bg: string): boolean {
  const rgba = bg.match(/^rgba?\(([^)]+)\)$/);
  if (rgba) {
    const parts = rgba[1].split(',').map((p) => p.trim());
    return parts.length === 4 && Number(parts[3]) === 0;
  }
  const alpha = bg.match(/\/\s*([\d.]+)\s*\)$/);
  if (alpha) return Number(alpha[1]) === 0;
  return bg === 'transparent';
}

/** Alpha channel of a computed background-color, or null when it cannot be read. */
function bgAlpha(bg: string): number | null {
  const rgba = bg.match(/^rgba?\(([^)]+)\)$/);
  if (rgba) {
    const parts = rgba[1].split(',').map((p) => p.trim());
    return parts.length === 4 ? Number(parts[3]) : 1;
  }
  const alpha = bg.match(/\/\s*([\d.]+)\s*\)$/);
  if (alpha) return Number(alpha[1]);
  return bg === 'transparent' ? 0 : null;
}

/** The ? button's hover tint: primary at 10% — faint, never the old solid grey. */
function expectFaintTint(alpha: number | null) {
  expect(alpha).not.toBeNull();
  expect(alpha as number).toBeGreaterThan(0.05);
  expect(alpha as number).toBeLessThan(0.2);
}

test('bell button: no background at rest, faint tint on hover', async ({ page }) => {
  await login(page);
  await page.goto('/dashboard');
  const bell = page.locator('button[aria-label^="اعلان"]').first();
  await expect(bell).toBeVisible();

  const restBg = await bell.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(isTransparent(restBg)).toBe(true);

  await bell.hover();
  await page.waitForTimeout(300);
  const hoverBg = await bell.evaluate((el) => getComputedStyle(el).backgroundColor);
  expectFaintTint(bgAlpha(hoverBg));
});

test('theme toggle: no background at rest, faint tint on hover', async ({ page }) => {
  await login(page);
  await page.goto('/dashboard');
  const toggle = page.locator('label.swap').first();
  await expect(toggle).toBeVisible();

  const restBg = await toggle.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(isTransparent(restBg)).toBe(true);

  await toggle.hover();
  await page.waitForTimeout(300);
  const hoverBg = await toggle.evaluate((el) => getComputedStyle(el).backgroundColor);
  expectFaintTint(bgAlpha(hoverBg));
});

test('bell dropdown still toggles', async ({ page }) => {
  await login(page);
  await page.goto('/dashboard');
  const bell = page.locator('button[aria-label^="اعلان"]').first();
  await expect(bell).toBeVisible();

  await bell.click();
  await expect(page.locator('h3', { hasText: 'اعلان‌ها' }).first()).toBeVisible({ timeout: 15000 });
  await bell.click();
  await expect(page.locator('h3', { hasText: 'اعلان‌ها' }).first()).toBeHidden({ timeout: 15000 });
});
