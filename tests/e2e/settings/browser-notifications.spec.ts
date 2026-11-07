import type { Locator, Page } from '@playwright/test';
import { test, expect, login, waitForLivewire, waitForToast } from '../shared/fixtures';

/**
 * #1379 — the «اعلان مرورگر» toggle must work under the CSP-safe build.
 *
 * The old handler was a single `x-on:change` expression using `'Notification'
 * in window` and an arrow callback. Livewire's CSP evaluator tokenises `in`
 * but never evaluates it (no `parseRelational` case, no BinaryExpression case,
 * no KEYWORD case in `parsePrimary`), and it does not parse arrows either — so
 * the expression threw at init time, the listener never attached, and clicking
 * the toggle did nothing but log an error. The logic now lives in a plain-JS
 * `toggleBrowserNotifications()` method on the layout-level `globalToast`
 * Alpine component, and the directive is a bare call.
 *
 * Behaviours pinned here (identical to the old handler):
 *  - granted    → toggle stays on, `requestPermission()` was called, no toast
 *  - denied     → toggle flips back off + «دسترسی اعلان مرورگر رد شد» toast
 *  - unsupported (`Notification` absent) → toggle flips back off +
 *                 «مرورگر شما از اعلان‌ها پشتیبانی نمی‌کند» toast
 *
 * Every case runs on a fresh page load AND after a `wire:navigate` SPA swap —
 * the morph path is exactly where the CSP evaluator used to fire.
 */

const DENIED_TOAST = 'دسترسی اعلان مرورگر رد شد';
const UNSUPPORTED_TOAST = 'مرورگر شما از اعلان‌ها پشتیبانی نمی‌کند';

interface CspViolation {
  directive: string;
  blocked: string;
  disposition: string;
  sourceFile: string;
}

const consoleErrors: string[] = [];

test.beforeEach(async ({ page }) => {
  consoleErrors.length = 0;
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (error) => consoleErrors.push(error.message));
});

test.afterEach(() => {
  expect(consoleErrors, 'the page must log no console error').toEqual([]);
});

function browserNotificationsToggle(page: Page): Locator {
  return page.locator('label:has-text("اعلان مرورگر") input[type="checkbox"]').first();
}

/** Stub `Notification.requestPermission()` and count its calls. */
async function stubNotification(page: Page, permission: 'granted' | 'denied') {
  await page.addInitScript((result) => {
    const w = window as any;
    w.__notificationRequests = 0;
    Object.defineProperty(window, 'Notification', {
      configurable: true,
      writable: true,
      value: {
        permission: result,
        requestPermission: () => {
          w.__notificationRequests += 1;
          return Promise.resolve(result);
        },
      },
    });
  }, permission);
}

/** Remove `Notification` entirely, the browser-without-support path. */
async function removeNotification(page: Page) {
  await page.addInitScript(() => {
    Object.defineProperty(window, 'Notification', {
      configurable: true,
      writable: true,
      value: undefined,
    });
  });
}

/** Collect real browser `securitypolicyviolation` events for the whole test. */
async function collectCspViolations(page: Page) {
  await page.addInitScript(() => {
    const w = window as any;
    if (w.__csp) return;
    w.__csp = [];
    document.addEventListener('securitypolicyviolation', (event: SecurityPolicyViolationEvent) => {
      w.__csp.push({
        directive: event.effectiveDirective,
        blocked: event.blockedURI,
        disposition: event.disposition,
        sourceFile: event.sourceFile,
      });
    });
  });
}

async function expectNoCspViolations(page: Page) {
  const violations: CspViolation[] = await page.evaluate(() => (window as any).__csp ?? []);
  expect(violations, 'the toggle must not trigger a CSP violation').toEqual([]);
}

/** Reach /settings through the sidebar's `wire:navigate` link (no full reload). */
async function viaSidebar(page: Page, href: string, urlGlob: string) {
  const link: Locator = page.locator(`.drawer-side a[href="${href}"]`).first();

  // Section items live in a collapsed <details>; open it first.
  const collapsed = await link.evaluate((el: Element) => {
    const details = el.closest('details');
    return !!details && !details.open;
  });
  if (collapsed) {
    await link.locator('xpath=ancestor::details/summary').click({ force: true });
  }

  await link.scrollIntoViewIfNeeded();
  await link.click();
  await page.waitForURL(urlGlob, { timeout: 15000 });
  await page.waitForLoadState('networkidle');
}

async function openSettings(page: Page, mode: 'fresh' | 'navigate') {
  if (mode === 'fresh') {
    await page.goto('/settings');
  } else {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await viaSidebar(page, '/settings', '**/settings');
  }
  await page.waitForLoadState('networkidle');
}

/** Toggle the browser-notifications switch ON and settle the requests it fires. */
async function clickToggleOn(page: Page) {
  const toggle = browserNotificationsToggle(page);
  await expect(toggle).not.toBeChecked();

  const update = waitForLivewire(page);
  await toggle.click();
  await update;
  await page.waitForLoadState('networkidle');

  return toggle;
}

async function setup(page: Page, mode: 'fresh' | 'navigate') {
  await collectCspViolations(page);
  await login(page);
  await openSettings(page, mode);
}

const MODES = [
  ['fresh load', 'fresh'],
  ['wire:navigate', 'navigate'],
] as const;

test.describe('browser notifications toggle — supported (#1379)', () => {
  for (const [label, mode] of MODES) {
    test(`granted → toggle stays on, no toast, no console errors (${label})`, async ({ page }) => {
      await stubNotification(page, 'granted');
      await setup(page, mode);

      const toggle = await clickToggleOn(page);

      await expect(toggle).toBeChecked();
      expect(await page.evaluate(() => (window as any).__notificationRequests)).toBe(1);
      await expect(page.locator('.toast')).toBeHidden();
      await expectNoCspViolations(page);
    });

    test(`denied → toggle flips back off + denial toast (${label})`, async ({ page }) => {
      await stubNotification(page, 'denied');
      await setup(page, mode);

      await clickToggleOn(page);

      await expect(browserNotificationsToggle(page)).not.toBeChecked();
      await expect(await waitForToast(page, DENIED_TOAST)).toBeVisible();
      expect(await page.evaluate(() => (window as any).__notificationRequests)).toBe(1);
      await expectNoCspViolations(page);
    });
  }
});

test.describe('browser notifications toggle — Notification absent (#1379)', () => {
  for (const [label, mode] of MODES) {
    test(`toggle flips back off + unsupported toast (${label})`, async ({ page }) => {
      await removeNotification(page);
      await setup(page, mode);

      await clickToggleOn(page);

      await expect(browserNotificationsToggle(page)).not.toBeChecked();
      await expect(await waitForToast(page, UNSUPPORTED_TOAST)).toBeVisible();
      await expectNoCspViolations(page);
    });
  }
});
