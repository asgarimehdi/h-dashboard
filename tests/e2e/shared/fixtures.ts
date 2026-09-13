import { test as base, expect, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// ---------------------------------------------------------------------------
// Run-scoped state (written by global-setup.ts, removed by global-teardown.ts)
// ---------------------------------------------------------------------------
const RUN_STATE_PATH = path.join(__dirname, '..', '.run-state.json');

function readRunState(): { runId: string; pwdNCode: string } {
  if (!fs.existsSync(RUN_STATE_PATH)) {
    throw new Error(
      '.run-state.json not found. global-setup.ts must run before any spec. ' +
      'Ensure playwright.config.ts has globalSetup configured.',
    );
  }
  return JSON.parse(fs.readFileSync(RUN_STATE_PATH, 'utf-8'));
}

/**
 * Per-run unique prefix for creating records that won't collide across
 * parallel workers sharing the same E2E database.
 */
export function getRunPrefix(): string {
  return `E2E-${readRunState().runId}`;
}

/**
 * Dedicated n_code for password-change tests. Each run creates its own
 * Person + User + Unit, so password-change.spec.ts never touches shared accounts.
 */
export function getPwdNCode(): string {
  return readRunState().pwdNCode;
}

// ---------------------------------------------------------------------------
// Test credentials — read strictly from environment, throw if missing
// ---------------------------------------------------------------------------
function requireEnv(name: string): string {
  const val = process.env[name];
  if (!val) {
    throw new Error(
      `Required env var ${name} is not set. Copy .env.e2e.example to .env.e2e and fill credentials.`,
    );
  }
  return val;
}

const TEST_USER = {
  nCode: requireEnv('TEST_N_CODE'),
  password: requireEnv('TEST_PASSWORD'),
  name: process.env.TEST_USER_NAME || 'مهدی عسگری',
};

// Role-specific national codes (all share the same password from seeders)
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

export const test = base;

export {
  expect,
  login,
  logout,
  waitForLivewire,
  waitForSearchResults,
  waitForToast,
  TEST_USER,
  ROLE_ACCOUNTS,
};
