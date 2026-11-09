// Bell button must carry no button background — like its header neighbours
// (theme toggle, help button). Regression test for the sticky grey hover box
// the `btn` base class painted on the notification bell.
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

test('bell button has no background at rest or on hover', async ({ page }) => {
  await login(page);
  await page.goto('/dashboard');
  const bell = page.locator('button[aria-label^="اعلان"]').first();
  await expect(bell).toBeVisible();

  const restBg = await bell.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(isTransparent(restBg)).toBe(true);

  await bell.hover();
  await page.waitForTimeout(300);
  const hoverBg = await bell.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(isTransparent(hoverBg)).toBe(true);
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
