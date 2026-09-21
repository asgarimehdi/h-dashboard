import { test, expect, login, logout, TEST_USER } from '../shared/fixtures';

/**
 * Plan 002 — Navigation & Sidebar E2E
 *
 * The sidebar is the primary navigation surface: a MaryUI `x-menu activate-by-route`
 * inside a collapsible drawer (`#main-drawer`). It renders 8 collapsible sections
 * as native `<details>/<summary>` and each menu item as `<li><a wire:navigate>`.
 *
 * DOM facts (probed against the live app, NOT guessed):
 * - Section headers  = `.drawer-side summary` (collapsible), text = Persian title
 * - Menu items       = `.drawer-side a[href]`
 * - Active highlight = `a.mary-active-menu` (+ `data-current` attr) — NOT `.menu-active`
 * - Mobile hamburger = `label[for="main-drawer"]`; drawer state = `#main-drawer` checkbox
 * - Header profile   = direct link `/profile` (NO dropdown exists — plan case 6 adapted)
 */

const SECTION_TITLES = [
  'منابع انسانی',
  'مدیریت تیکت‌ها',
  'مدیریت سازمان',
  'کار با نقشه',
  'ابزارهای مدیریتی',
  'گزارش‌ها',
  'مدیریت',
  'راهنما و پشتیبانی',
];

const STANDALONE_ITEMS = ['جستجوی کلی', 'صفحه اول', 'پروفایل من', 'تنظیمات'];

test.describe('sidebar structure', () => {
  test('renders all 8 collapsible sections', async ({ page }) => {
    await login(page);
    const summaries = page.locator('.drawer-side summary');

    for (const title of SECTION_TITLES) {
      await expect(summaries.filter({ hasText: title }).first()).toBeVisible();
    }

    // collapsed-by-default sanity: first section's children are hidden until toggled
    const firstDetails = summaries.first().locator('..');
    await expect(firstDetails.locator('ul a').first()).toBeHidden();
  });

  test('renders standalone menu items', async ({ page }) => {
    await login(page);
    const drawer = page.locator('.drawer-side');

    for (const title of STANDALONE_ITEMS) {
      await expect(drawer.locator(`a:has-text("${title}")`).first()).toBeVisible();
    }
  });
});

test.describe('section expand/collapse', () => {
  test('toggles submenu on summary click', async ({ page }) => {
    await login(page);

    // منابع انسانی is the first section
    const section = page.locator('.drawer-side summary', { hasText: 'منابع انسانی' }).first();
    const details = section.locator('..');
    const submenu = details.locator('ul a').first();

    // initially collapsed
    await expect(submenu).toBeHidden();

    // toggle open
    await section.click();
    await expect(submenu).toBeVisible();
    await expect(submenu).toHaveAttribute('href', '/kargozini/estekhdams');

    // toggle closed again
    await section.click();
    await expect(submenu).toBeHidden();
  });
});

test.describe('active state highlight', () => {
  test('highlights the current page in the menu', async ({ page }) => {
    await login(page);

    // Navigate to settings (standalone item)
    await page.goto('/settings');
    await page.waitForLoadState('networkidle');

    const active = page.locator('.drawer-side a.mary-active-menu');
    await expect(active).toHaveAttribute('href', '/settings');

    // Now navigate to a section item — the active mark should move
    await page.goto('/users');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.drawer-side a.mary-active-menu')).toHaveAttribute('href', '/users');
  });
});

test.describe('mobile drawer', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('hamburger opens the drawer', async ({ page }) => {
    await login(page);

    const drawerInput = page.locator('#main-drawer');
    // `.first()` — the hamburger label comes before the drawer-overlay close label,
    // and both target #main-drawer so the plain `label[for="main-drawer"]` is ambiguous.
    const hamburger = page.locator('label[for="main-drawer"]:not(.drawer-overlay)');

    await expect(hamburger).toBeVisible();
    await expect(drawerInput).not.toBeChecked();

    await hamburger.click();
    await expect(drawerInput).toBeChecked();

    // can navigate from the now-visible drawer
    await page.locator('.drawer-side a[href="/settings"]').click();
    await page.waitForURL('**/settings');
  });
});

test.describe('header navigation', () => {
  // The top `x-nav` header (with the profile link) is `lg:hidden` — mobile only.
  test.use({ viewport: { width: 390, height: 844 } });

  test('profile link in header navigates to /profile', async ({ page }) => {
    await login(page);

    // Header profile link (mobile nav) — direct link, no dropdown exists in this app
    const profileLink = page.locator('a[href="/profile"]').first();
    await expect(profileLink).toBeVisible();

    await profileLink.click();
    await page.waitForURL('**/profile');
    // On mobile the drawer auto-collapses after navigation, so the active sidebar
    // item is hidden — assert on the loaded page instead.
    await expect(page).toHaveURL(/\/profile/);
  });
});