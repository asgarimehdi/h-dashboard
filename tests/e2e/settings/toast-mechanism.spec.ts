import * as fs from 'node:fs';
import type { Locator, Page } from '@playwright/test';
import { test, expect, login, waitForLivewire, waitForToast, ROLE_ACCOUNTS } from '../shared/fixtures';

/**
 * Plan 013b — Global toast mechanism verification (Part 1 of #1377)
 *
 * MaryUI's Toast trait emits `$this->js('toast({...})')`, and Livewire's CSP
 * evaluator resolves that bare `toast` identifier by walking the ancestor
 * `_x_dataStack` — never `window` (`window.toast` is undefined by design, see
 * `resources/views/components/csp-toast.blade.php`). If the walk breaks, the
 * `$this->js` call throws silently and NO toast is ever rendered.
 *
 * Three triggers × three load modes = 9 cases. Each one asserts the toast is
 * actually visible (so the evaluator reached `globalToast` on `<body>`) and
 * that the page logged no console error.
 *
 * Triggers:
 *  1. settings save     → success toast «تنظیمات ذخیره شد!»
 *  2. restore success   → success toast «بازگردانی «tickets» انجام شد: N ردیف.»
 *  3. error toast       → error toast «برای بازگردانی جدول کاربران باید هشدار را تأیید کنید.»
 *
 * Load modes:
 *  - fresh:    `page.goto()` on a clean navigation
 *  - navigate: reach the page through the sidebar `wire:navigate` link (SPA
 *              swap, no full reload)
 *  - morph:    stay on the page, run a prior Livewire action first so the
 *              component (and its Alpine scope) has already been morphed, then
 *              run the measured action
 *
 * `users` is deliberately never restored: a users/full restore replaces the
 * account table mid-test and would invalidate the acting admin. The `tickets`
 * archive is read, restored and verified instead, and the `users` gate is used
 * only to trigger the error toast (no restore happens without confirmation).
 */

const BACKUP_TABLE = 'tickets';

/**
 * A CSP refusal of `$this->js('toast(...)')` shows up as a console error, not
 * as a missing toast only — collect both and fail the test on either.
 */
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

/** Arm before the click: resolves on the Livewire update the click triggers. */
function livewireUpdate(page: Page): Promise<unknown> {
  return waitForLivewire(page);
}

/**
 * Attach a file to a Livewire file input and wait until the component model
 * actually holds it.
 *
 * `setInputFiles()` only starts the upload; clicking an action before
 * `upload:finished` reaches the component sends `backupFile: null` and the
 * action fails validation with no toast. The two responses below are the
 * contract: the upload blob lands, then the model syncs.
 */
async function uploadFile(page: Page, file: string) {
  const uploaded = page.waitForResponse(
    (response) => /\/livewire[^/]*\/upload-file$/.test(new URL(response.url()).pathname),
    { timeout: 20000 },
  );

  const synced = page.waitForResponse(
    async (response) => {
      if (!/\/livewire[^/]*\/update$/.test(new URL(response.url()).pathname)) return false;
      try {
        return (await response.text()).includes('upload:finished');
      } catch {
        return false;
      }
    },
    { timeout: 20000 },
  );

  await page.locator('input[type="file"]').first().setInputFiles({
    name: 'table-backup.zip',
    mimeType: 'application/zip',
    buffer: fs.readFileSync(file),
  });

  await uploaded;
  await synced;
}

/** Reach a page through the sidebar's `wire:navigate` link (no full reload). */
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

/** Save the settings form and wait for its Livewire round-trip. */
async function saveSettings(page: Page) {
  const update = livewireUpdate(page);
  await page.getByRole('button', { name: 'ذخیره تنظیمات' }).click();
  await update;
}

/** Download a single-table archive and return its on-disk path. */
async function downloadTableBackup(page: Page, table: string): Promise<string> {
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: table, exact: true }).click();
  const download = await downloadPromise;
  const path = await download.path();
  if (!path) throw new Error('download produced no path');
  return path;
}

/** Change the restore target and wait for the `wire:model.live` round-trip. */
async function selectRestoreTarget(page: Page, table: string) {
  const update = livewireUpdate(page);
  await page.locator('select').first().selectOption(table);
  await update;
}

test.describe('toast mechanism — settings save', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('fresh load → settings save shows success toast', async ({ page }) => {
    await page.goto('/settings');
    await page.waitForLoadState('networkidle');

    await saveSettings(page);

    await expect(await waitForToast(page, 'تنظیمات ذخیره شد')).toBeVisible();
  });

  test('wire:navigate → settings save shows success toast', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    await viaSidebar(page, '/settings', '**/settings');
    await saveSettings(page);

    await expect(await waitForToast(page, 'تنظیمات ذخیره شد')).toBeVisible();
  });

  test('morph → settings save shows success toast', async ({ page }) => {
    await page.goto('/settings');
    await page.waitForLoadState('networkidle');

    // The compact-mode toggle is a live action that morphs the component
    // before the measured save runs. It is the second toggle on purpose: the
    // first one's `x-on:change` expression uses the `in` operator, which the
    // CSP build's parser rejects (a pre-existing issue, out of scope for #1377).
    const toggle = livewireUpdate(page);
    await page.locator('input[type="checkbox"].toggle').nth(1).click();
    await toggle;

    await saveSettings(page);

    await expect(await waitForToast(page, 'تنظیمات ذخیره شد')).toBeVisible();
  });
});

test.describe('toast mechanism — restore success (admin)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ROLE_ACCOUNTS.admin);
  });

  async function restoreDownloadedTable(page: Page) {
    const zipPath = await downloadTableBackup(page, BACKUP_TABLE);
    await selectRestoreTarget(page, BACKUP_TABLE);
    await uploadFile(page, zipPath);

    const update = livewireUpdate(page);
    await page.locator('button[wire\\:click="restoreBackup"]').click();
    await update;
  }

  test('fresh load → restore success shows success toast', async ({ page }) => {
    await page.goto('/tools/backup');
    await page.waitForLoadState('networkidle');

    await restoreDownloadedTable(page);

    await expect(await waitForToast(page, 'بازگردانی')).toBeVisible();
  });

  test('wire:navigate → restore success shows success toast', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    await viaSidebar(page, '/tools/backup', '**/tools/backup');
    await restoreDownloadedTable(page);

    await expect(await waitForToast(page, 'بازگردانی')).toBeVisible();
  });

  test('morph → restore success shows success toast', async ({ page }) => {
    await page.goto('/tools/backup');
    await page.waitForLoadState('networkidle');

    // Morph the component twice before the measured action (the users warning
    // appears and disappears with the target).
    await selectRestoreTarget(page, 'users');
    await selectRestoreTarget(page, BACKUP_TABLE);

    await restoreDownloadedTable(page);

    await expect(await waitForToast(page, 'بازگردانی')).toBeVisible();
  });
});

test.describe('toast mechanism — error toast (admin)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ROLE_ACCOUNTS.admin);
  });

  /**
   * Restoring `users` requires the confirmation checkbox (owner decision
   * Q2-(a)); clicking without it returns an error toast and changes nothing,
   * so the acting admin stays valid.
   */
  async function triggerUsersWarning(page: Page) {
    const zipPath = await downloadTableBackup(page, BACKUP_TABLE);
    await uploadFile(page, zipPath);

    const update = livewireUpdate(page);
    await page.locator('button[wire\\:click="restoreBackup"]').click();
    await update;
  }

  const WARNING = 'برای بازگردانی جدول کاربران باید هشدار را تأیید کنید';

  test('fresh load → error shows error toast', async ({ page }) => {
    await page.goto('/tools/backup');
    await page.waitForLoadState('networkidle');

    await selectRestoreTarget(page, 'users');
    await triggerUsersWarning(page);

    await expect(await waitForToast(page, WARNING)).toBeVisible();
  });

  test('wire:navigate → error shows error toast', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    await viaSidebar(page, '/tools/backup', '**/tools/backup');
    await selectRestoreTarget(page, 'users');
    await triggerUsersWarning(page);

    await expect(await waitForToast(page, WARNING)).toBeVisible();
  });

  test('morph → error shows error toast', async ({ page }) => {
    await page.goto('/tools/backup');
    await page.waitForLoadState('networkidle');

    // Morph away from `users` and back before the measured action.
    await selectRestoreTarget(page, BACKUP_TABLE);
    await selectRestoreTarget(page, 'users');

    await triggerUsersWarning(page);

    await expect(await waitForToast(page, WARNING)).toBeVisible();
  });
});
