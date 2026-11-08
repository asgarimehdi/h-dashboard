import { test, expect, login, logout, TEST_USER } from '../shared/fixtures';

type Page = import('@playwright/test').Page;

/**
 * Plan 002 — Navigation & Sidebar E2E
 *
 * The sidebar is the primary navigation surface: a MaryUI `x-menu activate-by-route`
 * inside a collapsible drawer (`#main-drawer`). It renders 9 collapsible sections
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
  'سخت‌افزار',
  'گزارش‌ها',
  'مدیریت',
  'راهنما و پشتیبانی',
];

const STANDALONE_ITEMS = ['جستجوی کلی', 'صفحه اول', 'پروفایل من', 'تنظیمات'];

test.describe('sidebar structure', () => {
  test('renders all 9 collapsible sections', async ({ page }) => {
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

  // #996 — the vendor's `Str::startsWith` prefix match highlighted several items
  // at once (`/maps/route2` lit `/map`, `/maps/route` AND itself). Every row here
  // is one page with exactly one owning sidebar link.
  const ACTIVE = '.drawer-side a.mary-active-menu';

  const ACTIVE_MATRIX = [
    { path: '/dashboard', href: '/dashboard' },
    { path: '/map', href: '/map' },
    { path: '/maps/route', href: '/maps/route' },
    { path: '/maps/route2', href: '/maps/route2' },   // رگرسیون #996
    { path: '/maps/point', href: '/maps/point' },     // رگرسیون #996
    { path: '/users', href: '/users' },
    { path: '/users?page=1', href: '/users' },        // کوئری نباید هایلایت را بشکند
    { path: '/users/changepassword', href: '/users/changepassword' },
    { path: '/tickets/new', href: '/tickets/new' },
  ];

  for (const { path, href } of ACTIVE_MATRIX) {
    test(`${path} highlights exactly its own sidebar link`, async ({ page }) => {
      await login(page);
      await page.goto(path);
      await page.waitForLoadState('domcontentloaded');

      await expect(page.locator(ACTIVE)).toHaveCount(1);
      await expect(page.locator(ACTIVE)).toHaveAttribute('href', href);
      await expect(page.locator('.drawer-side a[aria-current="page"]')).toHaveCount(1);
      await expect(page.locator(`.drawer-side a[href="${href}"][aria-current="page"]`)).toHaveCount(1);
    });
  }
});

test.describe('submenu open state (#1389)', () => {
  // The بیماری‌ها submenu holds the current page on /disease, so its
  // <details> must render open. Regression: CspMenuSub emitted
  // data-show=" true " (whitespace from the @if form), which never equalled
  // 'true' in maryMenuSub.init(), so every submenu initialised closed —
  // on full load and after a wire:navigate swap alike. Assert the `open`
  // PROPERTY, not only visibility: a collapsed <details> still gives its
  // children a non-empty box in Chromium.
  const diseaseDetails = (page: Page) =>
    page.locator('.drawer-side summary', { hasText: 'بیماری‌ها' }).first().locator('..');

  test('submenu of the current page is open on full load', async ({ page }) => {
    await login(page);
    await page.goto('/disease');
    await page.waitForLoadState('domcontentloaded');

    await expect(diseaseDetails(page)).toHaveJSProperty('open', true);
  });

  test('submenu stays open after a wire:navigate click', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    await page.waitForLoadState('domcontentloaded');

    // Arm a marker: it survives an SPA swap and vanishes on a full reload.
    await page.evaluate(() => {
      (window as unknown as { __probe?: string }).__probe = 'alive';
    });

    // No child is active on /dashboard, so open the submenu first.
    await page.locator('.drawer-side summary', { hasText: 'بیماری‌ها' }).first().click();
    await expect(diseaseDetails(page)).toHaveJSProperty('open', true);

    await page.locator('.drawer-side a[href="/disease"]').first().click();
    await page.waitForURL('**/disease');

    // Still a swap, not a full reload — and the submenu stayed open.
    expect(await page.evaluate(() => (window as unknown as { __probe?: string }).__probe)).toBe('alive');
    await expect(diseaseDetails(page)).toHaveJSProperty('open', true);
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