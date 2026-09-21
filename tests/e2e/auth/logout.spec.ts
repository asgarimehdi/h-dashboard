import { test, expect, login, logout } from '../shared/fixtures';

test.describe('Authentication — logout', () => {
  test('logout redirects to login and clears the session', async ({ page }) => {
    await login(page);
    await expect(page).toHaveURL((url) => url.pathname === '/dashboard');

    await logout(page);

    await expect(page).toHaveURL((url) => url.pathname === '/login');
  });

  test('dashboard is not accessible after logout', async ({ page }) => {
    await login(page);
    await logout(page);

    // Navigating back to a protected page must bounce to /login.
    await page.goto('/dashboard');
    await page.waitForURL((url) => url.pathname === '/login', { timeout: 10000 });
    await expect(page).toHaveURL((url) => url.pathname === '/login');
  });
});