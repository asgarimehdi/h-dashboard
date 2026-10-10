import { test, expect, login, waitForLivewire } from '../shared/fixtures';

/**
 * Issue #957 — accessibility infrastructure gate (steps 1–4).
 *
 * Companion to tests/Feature/AccessibilityInfrastructureTest.php, not a copy of
 * it. The PHP suite pins what the server renders; this one pins the three things
 * only a real browser can answer:
 *
 *   1. the skip link is genuinely FIRST in the tab order and really moves focus
 *      when activated — `tabindex="-1"` on a div is inert markup until the
 *      browser acts on it;
 *   2. Enter on a sortable header really re-sorts, i.e. the `@keydown` binding
 *      survives Alpine's parsing and Livewire's morph;
 *   3. the toast really lands inside a live region once a real toast fires.
 *
 * `maximum-scale=1.0` is deliberately NOT asserted anywhere: removing it is a
 * product decision (Mehdi's) and is out of this issue's scope.
 */

const SORTABLE_LOOKUP = '/kargozini/semats';

test.describe('skip link', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/profile');
  });

  test('is the first element the keyboard reaches', async ({ page }) => {
    await page.keyboard.press('Tab');

    const href = await page.evaluate(() => document.activeElement?.getAttribute('href'));
    expect(href).toBe('#main-content');
  });

  test('is visually collapsed until focused, then expands', async ({ page }) => {
    const skip = page.locator('a[href="#main-content"]').first();

    // `sr-only` is not `display:none` — it clips to a 1px box — so Playwright
    // still calls the element visible. Measure the box instead of asking about
    // visibility, or the assertion would be meaningless.
    const collapsed = await skip.boundingBox();
    expect(collapsed).not.toBeNull();
    expect(collapsed!.width).toBeLessThanOrEqual(1);

    await skip.focus();
    await expect(skip).toBeVisible();

    const expanded = await skip.boundingBox();
    expect(expanded!.width).toBeGreaterThan(10);
  });

  test('moves focus to the main region when activated', async ({ page }) => {
    await page.locator('a[href="#main-content"]').first().focus();
    await page.keyboard.press('Enter');

    // A div with tabindex="-1" is focusable only programmatically; without it
    // the browser moves the scroll position but the next Tab lands back in the
    // menu, which is exactly what the skip link exists to prevent.
    await expect(page.locator('#main-content')).toBeFocused();
  });
});

test.describe('navigation landmark', () => {
  // Scoped to `.drawer-side`: Laravel's paginator also renders a labelled
  // `<nav aria-label="Pagination Navigation">`, and on a paginated page it comes
  // first in the DOM. A bare `nav[aria-label]` would silently match that one.
  const SIDEBAR = '.drawer-side';

  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('the sidebar is a labelled nav containing the menu', async ({ page }) => {
    await page.goto('/users');
    await page.waitForLoadState('networkidle');

    const nav = page.locator(`${SIDEBAR} nav[aria-label]`).first();
    await expect(nav).toBeVisible();

    const label = ((await nav.getAttribute('aria-label')) ?? '').trim();
    expect(label).not.toBe('');

    await expect(nav.locator('.menu a[href]').first()).toBeVisible();
  });

  test('the current page is announced with aria-current', async ({ page }) => {
    await page.goto('/users');
    await page.waitForLoadState('networkidle');

    const current = page.locator(`${SIDEBAR} nav a[aria-current="page"]`);
    await expect(current).toHaveCount(1);
    await expect(current).toHaveAttribute('href', '/users');

    // A link that is not the current page must not claim to be.
    await expect(page.locator(`${SIDEBAR} nav a[href="/activity-log"][aria-current]`)).toHaveCount(0);
  });
});

test.describe('sortable table headers', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto(SORTABLE_LOOKUP);
    await page.waitForLoadState('networkidle');
  });

  test('exactly one header carries a real sort direction, the rest are none', async ({ page }) => {
    const headers = page.locator('table thead th[aria-sort]');

    // Both columns of this lookup are sortable; the trailing actions column is
    // not and must therefore carry no affordance at all.
    await expect(headers).toHaveCount(2);
    await expect(headers.nth(0)).toHaveAttribute('aria-sort', 'ascending');
    await expect(headers.nth(1)).toHaveAttribute('aria-sort', 'none');

    await expect(page.locator('table thead th[tabindex]')).toHaveCount(2);
  });

  test('Enter re-sorts and the announcement follows', async ({ page }) => {
    const header = page.locator('table thead th[aria-sort]').first();
    await expect(header).toHaveAttribute('aria-sort', 'ascending');

    await header.focus();
    await expect(header).toBeFocused();
    await page.keyboard.press('Enter');
    await waitForLivewire(page);

    await expect(header).toHaveAttribute('aria-sort', 'descending');
  });

  test('Space re-sorts too', async ({ page }) => {
    const header = page.locator('table thead th[aria-sort]').nth(1);
    await expect(header).toHaveAttribute('aria-sort', 'none');

    await header.focus();
    await page.keyboard.press('Space');
    await waitForLivewire(page);

    // Sorting an unsorted column starts ascending.
    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    await expect(page.locator('table thead th[aria-sort]').first()).toHaveAttribute('aria-sort', 'none');
  });

  test('mouse sorting still works — the keyboard change did not replace it', async ({ page }) => {
    const header = page.locator('table thead th[aria-sort]').first();
    await header.click();
    await waitForLivewire(page);

    await expect(header).toHaveAttribute('aria-sort', 'descending');
  });
});

test.describe('announcement regions', () => {
  test('a fired toast lands inside a polite live region', async ({ page }) => {
    await login(page);
    await page.goto('/profile');
    await page.waitForLoadState('networkidle');

    await page.evaluate(() => {
      const w = window as unknown as { toast: (p: unknown) => void };
      w.toast({
        toast: {
          type: 'success',
          title: 'انجام شد',
          description: 'تغییرات ذخیره شد',
          position: 'toast-bottom',
          icon: '',
          css: 'alert-success',
          timeout: 60000,
          noProgress: true,
          progressClass: null,
        },
      });
    });

    const toast = page.locator('.toast').first();
    await expect(toast).toBeVisible();

    // Containment, not proximity: a live region elsewhere on the page would
    // never announce this toast.
    const insideLiveRegion = await toast.evaluate(
      (el) => el.closest('[aria-live="polite"]') !== null,
    );
    expect(insideLiveRegion).toBe(true);
  });

  test('a failed sign-in is announced as an alert', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#n_code', '1111111111');
    await page.fill('#password', 'wrong-password');
    await page.click('button[type="submit"]');

    const alert = page.locator('[role="alert"]');
    await expect(alert).toBeVisible({ timeout: 15000 });

    // An empty role is not an announcement — the message itself has to be in it.
    const text = (await alert.innerText()).trim();
    expect(text.length).toBeGreaterThan(0);
    expect(text).toContain('اشتباه');
  });
});
