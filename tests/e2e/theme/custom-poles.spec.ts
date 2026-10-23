import { test, expect, login, waitForToast } from '../shared/fixtures';
import { installThemeHelpers, THEMES, VIEWPORT } from './theme-helpers';

/**
 * #1224 — the settings page lets a user pick one light and one dark pole, and
 * MaryUI's `<x-theme-toggle>` cycles between exactly those two.
 *
 * This spec is the mandatory e2e gate from the issue: it proves the real
 * component (vendor code is not in git) honours dynamic poles, that saving
 * migrates an applied theme which is no longer a pole (scenario 1), that the
 * `mary-theme` localStorage value keeps MaryUI's `$persist` JSON format, and
 * that guests keep the pre-issue fantasy/dark behaviour.
 */

test.use({ viewport: VIEWPORT });

// The flow mutates one user's settings JSON column; parallel workers would race.
test.describe.configure({ mode: 'serial' });

const LIGHT_SELECT = 'select[name="lightTheme"]';
const DARK_SELECT = 'select[name="darkTheme"]';
const TOGGLE = 'input.theme-controller';
const SAVE = 'ذخیره تنظیمات';

async function themeOf(page: import('@playwright/test').Page): Promise<string | null> {
  return page.evaluate(() => document.documentElement.getAttribute('data-theme'));
}

async function storedTheme(page: import('@playwright/test').Page): Promise<string | null> {
  return page.evaluate(() => localStorage.getItem('mary-theme'));
}

/** Save the current selects and wait for the Livewire round-trip toast. */
async function save(page: import('@playwright/test').Page): Promise<void> {
  await page.getByRole('button', { name: SAVE }).click();
  await waitForToast(page);
}

/** Reset the user's poles to the pre-issue defaults, then apply `dark`. */
async function reset(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/settings');
  await page.waitForLoadState('networkidle');

  await page.selectOption(LIGHT_SELECT, 'fantasy');
  await page.selectOption(DARK_SELECT, 'dark');
  await save(page);

  await page.evaluate(() => {
    localStorage.setItem('mary-theme', JSON.stringify('dark'));
    localStorage.setItem('mary-class', JSON.stringify('dark'));
  });
  await page.reload();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
}

test.describe('custom theme poles', () => {
  test.beforeEach(async ({ page }) => {
    await installThemeHelpers(page);
    await login(page);
    await reset(page);
  });

  test('saving new poles migrates the applied theme and keeps the JSON format', async ({ page }) => {
    // Record the re-broadcast that keeps layout blobs / charts in sync.
    await page.evaluate(() => {
      (window as unknown as { __themeEvents: string[] }).__themeEvents = [];
      window.addEventListener('theme-changed', (e) => {
        (window as unknown as { __themeEvents: string[] }).__themeEvents.push(
          (e as CustomEvent<string>).detail
        );
      });
    });

    await page.selectOption(LIGHT_SELECT, 'cupcake');
    await page.selectOption(DARK_SELECT, 'coffee');
    await save(page);

    // Applied theme was `dark`, which is no longer a pole: scenario 1 says it
    // migrates to the same-family pole (coffee), not to the light pole.
    await expect.poll(() => themeOf(page)).toBe('coffee');

    // MaryUI's $persist stores a JSON string, quotes included. The head script
    // and the storage listener strip them with replaceAll('"').
    expect(await storedTheme(page)).toBe('"coffee"');

    const events = await page.evaluate(
      () => (window as unknown as { __themeEvents: string[] }).__themeEvents
    );
    expect(events).toContain('coffee');

    // The toggle in the header now advertises the two new poles.
    const toggleHtml = await page.locator('.swap').first().evaluate((el) => el.outerHTML);
    expect(toggleHtml).toContain("? 'coffee' : 'cupcake'");
  });

  test('the header toggle cycles between the two new poles', async ({ page }) => {
    await page.selectOption(LIGHT_SELECT, 'cupcake');
    await page.selectOption(DARK_SELECT, 'coffee');
    await save(page);
    await expect.poll(() => themeOf(page)).toBe('coffee');

    // The Alpine state still holds the old `dark` pole, so the first click goes
    // to the light pole; the second lands on the new dark pole.
    await page.locator(TOGGLE).first().click({ force: true });
    await expect.poll(() => themeOf(page)).toBe('cupcake');
    expect(await storedTheme(page)).toBe('"cupcake"');

    await page.locator(TOGGLE).first().click({ force: true });
    await expect.poll(() => themeOf(page)).toBe('coffee');
    expect(await storedTheme(page)).toBe('"coffee"');
  });

  test('poles and the applied theme survive a reload', async ({ page }) => {
    await page.selectOption(LIGHT_SELECT, 'cupcake');
    await page.selectOption(DARK_SELECT, 'coffee');
    await save(page);
    await page.locator(TOGGLE).first().click({ force: true });
    await expect.poll(() => themeOf(page)).toBe('cupcake');

    await page.reload();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'cupcake');
    await expect(page.locator(LIGHT_SELECT)).toHaveValue('cupcake');
    await expect(page.locator(DARK_SELECT)).toHaveValue('coffee');

    // A second device would load the server-side poles (the toggle markup).
    const toggleHtml = await page.locator('.swap').first().evaluate((el) => el.outerHTML);
    expect(toggleHtml).toContain("? 'coffee' : 'cupcake'");
  });

  test('a change in another tab reaches the layout storage listener', async ({ page, context }) => {
    const other = await context.newPage();
    await other.goto('/settings');
    await other.waitForLoadState('networkidle');

    await page.evaluate(() => {
      (window as unknown as { __storageKeys: string[] }).__storageKeys = [];
      window.addEventListener('storage', (e) => {
        (window as unknown as { __storageKeys: string[] }).__storageKeys.push(e.key ?? '');
      });
    });

    await other.evaluate(() => localStorage.setItem('mary-theme', JSON.stringify('synthwave')));

    await expect
      .poll(() =>
        page.evaluate(() => (window as unknown as { __storageKeys: string[] }).__storageKeys)
      )
      .toContain('mary-theme');

    // The layout listener recreates the blobs on a theme storage event; it must
    // not throw or empty the container.
    await page.waitForTimeout(250);
    expect(await page.locator('.stitch-blobs > *').count()).toBeGreaterThan(0);

    await other.close();
  });

  test('the two selects list every enabled theme in its own colour-scheme family', async ({ page }) => {
    const optionsOf = (selector: string) =>
      page
        .locator(`${selector} option`)
        .evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value));

    const lightOptions = await optionsOf(LIGHT_SELECT);
    const darkOptions = await optionsOf(DARK_SELECT);

    // Every enabled theme is offered exactly once, across the two selects.
    expect([...lightOptions, ...darkOptions].sort()).toEqual([...THEMES].sort());

    // The split is not aesthetic: daisyUI declares `color-scheme` per theme, and
    // App\Support\ThemeFamilies must agree with the theme's own tokens.
    for (const [themes, expected] of [
      [lightOptions, 'light'],
      [darkOptions, 'dark'],
    ] as const) {
      for (const theme of themes) {
        const scheme = await page.evaluate((t) => {
          document.documentElement.setAttribute('data-theme', t);
          return getComputedStyle(document.documentElement).colorScheme;
        }, theme);
        expect(scheme, `${theme} is listed as a ${expected} pole`).toContain(expected);
      }
    }
  });

  test('a guest still toggles the default fantasy/dark pair on the login page', async ({ browser }) => {
    const context = await browser.newContext({ viewport: VIEWPORT, locale: 'fa-IR' });
    const guest = await context.newPage();
    await guest.goto('/login');
    await guest.waitForLoadState('networkidle');

    const toggle = guest.locator(TOGGLE).first();
    await expect(toggle).toBeVisible();

    // No settings → the markup keeps the pre-issue poles.
    const html = await guest.locator('.swap').first().evaluate((el) => el.outerHTML);
    expect(html).toContain("? 'dark' : 'fantasy'");

    await toggle.click({ force: true });
    await expect.poll(() => themeOf(guest)).toBe('dark');
    await toggle.click({ force: true });
    await expect.poll(() => themeOf(guest)).toBe('fantasy');

    await context.close();
  });
});
