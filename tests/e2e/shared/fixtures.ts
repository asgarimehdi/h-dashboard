import { test as base, expect, type Page, type BrowserContext } from '@playwright/test';

// Test credentials — read from environment variables with fallback defaults
const TEST_USER = {
  nCode: process.env.TEST_N_CODE || '4411015056',
  password: process.env.TEST_PASSWORD || '12345678',
  name: process.env.TEST_USER_NAME || 'مهدی عسگری',
};

// Role-specific national codes (all share the same password)
const ROLE_ACCOUNTS: Record<string, string> = {
  admin: TEST_USER.nCode,
  unit_manager: process.env.TEST_UNIT_MANAGER_N_CODE || '6275537615',
  expert: process.env.TEST_EXPERT_N_CODE || '0023548258',
  user: process.env.TEST_REGULAR_USER_N_CODE || '0041368464',
};

/**
 * Login helper - fills login form and waits for redirect to dashboard
 */
async function login(page: Page, nCode = TEST_USER.nCode, password = TEST_USER.password) {
  await page.goto('/login');
  await page.fill('#n_code', nCode);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');
  // Wait for redirect away from login page
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
}

/**
 * Logout helper — the real logout is a POST form in the sidebar:
 * <form method="POST" action="/logout"> with a submit button (icon o-power, tooltip "logoff").
 */
async function logout(page: Page) {
  const logoutBtn = page.locator('form[action*="logout"] button[type="submit"]').first();
  await logoutBtn.click();
  await page.waitForURL('**/login', { timeout: 10000 });
}

/**
 * Wait for Livewire request to complete
 */
async function waitForLivewire(page: Page) {
  // Livewire adds .wire-loading class during requests
  await page.waitForFunction(() => {
    return !document.querySelector('.wire-loading') ||
           document.querySelectorAll('.wire-loading[style*="display: none"]').length > 0;
  }, { timeout: 15000 });
}

/**
 * Wait for a Livewire-debounced search result to appear
 */
async function waitForSearchResults(page: Page, selector: string) {
  await page.waitForSelector(selector, { state: 'visible', timeout: 10000 });
}

/**
 * Wait for toast notification
 */
async function waitForToast(page: Page, text?: string) {
  const toast = text
    ? page.locator(`.toast:has-text("${text}")`)
    : page.locator('.toast').first();
  await toast.waitFor({ state: 'visible', timeout: 10000 });
  return toast;
}

// Custom test fixture
type TestFixtures = {
  authenticatedPage: Page;
  adminPage: Page;
};

export const test = base.extend<TestFixtures>({
  authenticatedPage: async ({ page }, use) => {
    await login(page);
    await use(page);
  },

  adminPage: async ({ page }, use) => {
    await login(page);
    // Verify we're on dashboard (admin should have full access)
    await page.waitForURL('**/dashboard');
    await use(page);
  },
});

export { expect, login, logout, waitForLivewire, waitForSearchResults, waitForToast, TEST_USER, ROLE_ACCOUNTS };
