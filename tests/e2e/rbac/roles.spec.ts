import { test, expect } from '../shared/fixtures';

/**
 * Plan 003 — RBAC & Authorization E2E
 *
 * Spatie roles/permissions gate every module from the browser. We log in as each
 * of the 4 seeded roles (all share password 12345678 — see PersonUserFromDeviceSeeder)
 * and assert (1) the sidebar menu is filtered per role and (2) direct navigation to
 * forbidden routes yields HTTP 403, never a data leak.
 *
 * Probed DOM facts (not guessed):
 * - Sidebar sections = `.drawer-side summary` (collapsible), menu items = `.drawer-side a[href]`
 * - Forbidden navigation returns HTTP 403 with body "403 ... access rights"
 * - admin sidebar has 8 sections; unit_manager 4; expert/user 3
 */

const ROLE_ACCOUNTS = {
  admin: '4411015056',
  unit_manager: '6275537615',
  expert: '0023548258',
  user: '0041368464',
};
const PASSWORD = '12345678';

// Sidebar section titles each role should see (subset of the admin's 8).
const EXPECTED_SECTIONS: Record<string, string[]> = {
  admin: [
    'منابع انسانی', 'مدیریت تیکت‌ها', 'مدیریت سازمان', 'کار با نقشه',
    'ابزارهای مدیریتی', 'گزارش‌ها', 'مدیریت', 'راهنما و پشتیبانی',
  ],
  // unit_manager: no HR, no maps, no tools, no management
  unit_manager: ['مدیریت تیکت‌ها', 'مدیریت سازمان', 'گزارش‌ها', 'راهنما و پشتیبانی'],
  expert: ['مدیریت تیکت‌ها', 'گزارش‌ها', 'راهنما و پشتیبانی'],
  user: ['مدیریت تیکت‌ها', 'گزارش‌ها', 'راهنما و پشتیبانی'],
};

// hrefs that must (or must not) appear in a role's sidebar.
const EVERYONE_ITEMS = ['/profile', '/settings', '/users/changepassword', '/search'];
const ADMIN_ONLY_ITEMS = ['/users', '/roles', '/permissions', '/hardware', '/tools'];

async function loginAs(page, nCode: string) {
  await page.goto('/login');
  await page.fill('#n_code', nCode);
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
}

async function drawerHrefs(page) {
  return page.locator('.drawer-side a[href]').evaluateAll((as) =>
    as.map((a) => a.getAttribute('href') || ''),
  );
}

test.describe('RBAC — sidebar menu per role', () => {
  for (const [role, nCode] of Object.entries(ROLE_ACCOUNTS)) {
    test(`${role} sees expected sidebar sections`, async ({ page }) => {
      await loginAs(page, nCode);
      const summaries = page.locator('.drawer-side summary');
      const titles = await summaries.evaluateAll((s) => s.map((x) => x.textContent!.trim()));

      const expected = EXPECTED_SECTIONS[role];
      for (const t of expected) {
        expect(titles, `${role} should see section "${t}"`).toContain(t);
      }
    });

    test(`${role} sidebar hides admin-only items correctly`, async ({ page }) => {
      await loginAs(page, nCode);
      const hrefs = await drawerHrefs(page);

      for (const item of EVERYONE_ITEMS) {
        expect(hrefs, `${role} should always see ${item}`).toContain(item);
      }

      if (role !== 'admin') {
        for (const item of ADMIN_ONLY_ITEMS) {
          expect(hrefs, `${role} should NOT see ${item}`).not.toContain(item);
        }
      } else {
        for (const item of ADMIN_ONLY_ITEMS) {
          expect(hrefs, `admin should see ${item}`).toContain(item);
        }
      }
    });
  }
});

test.describe('RBAC — forbidden direct navigation returns 403', () => {
  const FORBIDDEN_FOR_NON_ADMIN = ['/users', '/roles', '/permissions', '/hardware'];

  for (const [role, nCode] of Object.entries(ROLE_ACCOUNTS)) {
    if (role === 'admin') continue; // admin is allowed everywhere

    for (const path of FORBIDDEN_FOR_NON_ADMIN) {
      test(`${role} cannot access ${path}`, async ({ page }) => {
        await loginAs(page, nCode);
        const resp = await page.goto(path);
        // Livewire renders the 403 inside the SPA, so assert both status and body.
        expect(resp!.status()).toBe(403);
        await expect(page.locator('body')).toContainText('access rights');
        // Crucially: no admin-only data rendered.
        await expect(page.locator('table tbody tr')).toHaveCount(0);
      });
    }
  }

  test('admin CAN access /users (200, data present)', async ({ page }) => {
    await loginAs(page, ROLE_ACCOUNTS.admin);
    const resp = await page.goto('/users');
    expect(resp!.status()).toBe(200);
    // the users list renders a table
    await expect(page.locator('table').first()).toBeVisible();
  });
});

test.describe('RBAC — session end', () => {
  test('protected route redirects to /login after logout', async ({ page }) => {
    await loginAs(page, ROLE_ACCOUNTS.admin);
    await page.locator('form[action*="logout"] button[type="submit"]').first().click();
    await page.waitForURL((url) => url.pathname === '/login', { timeout: 10000 });

    await page.goto('/users');
    await page.waitForURL((url) => url.pathname === '/login', { timeout: 10000 });
    await expect(page).toHaveURL((url) => url.pathname === '/login');
  });
});