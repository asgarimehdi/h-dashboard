import { test as base, expect, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// --- Run state (written by global-setup.ts) ---
const RUN_STATE_PATH = path.join(process.cwd(), 'tests', 'e2e', '.run-state.json');

function readRunState(): { runId: string; pwdNCode: string } {
  if (!fs.existsSync(RUN_STATE_PATH)) {
    throw new Error(
      '.run-state.json not found. Run globalSetup first (it generates this file).',
    );
  }
  return JSON.parse(fs.readFileSync(RUN_STATE_PATH, 'utf-8'));
}

const runState = readRunState();
export const runId = runState.runId;
export const pwdNCode = runState.pwdNCode;

// --- Test credentials — STRICT from env, no fallbacks ---
function requireEnv(name: string): string {
  const val = process.env[name];
  if (!val) throw new Error(`${name} env var is required. Set it in .env.e2e`);
  return val;
}

const TEST_USER = {
  nCode: requireEnv('TEST_N_CODE'),
  password: requireEnv('TEST_PASSWORD'),
  name: process.env.TEST_USER_NAME || '',
};

// Role-specific national codes (all share the same password)
const ROLE_ACCOUNTS: Record<string, string> = {
  admin: TEST_USER.nCode,
  unit_manager: requireEnv('TEST_UNIT_MANAGER_N_CODE'),
  expert: requireEnv('TEST_EXPERT_N_CODE'),
  user: requireEnv('TEST_REGULAR_USER_N_CODE'),
};

/**
 * Login helper - fills login form and waits for redirect to dashboard
 */
async function login(page: Page, nCode = TEST_USER.nCode, password = TEST_USER.password) {
  await page.goto('/login');
  await page.fill('#n_code', nCode);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
}

/**
 * Logout helper
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

export const test = base;

export { expect, login, logout, waitForLivewire, waitForSearchResults, waitForToast, TEST_USER, ROLE_ACCOUNTS };
