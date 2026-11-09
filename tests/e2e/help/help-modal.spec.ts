import { test, expect, login } from '../shared/fixtures';

/**
 * #1263 — the help button must open a populated modal.
 *
 * The modal body is server-gated (#1166), so the button has to commit
 * `showHelpModal = true` through Livewire (`$set`) instead of assigning
 * `$wire.showHelpModal = true`, which never sends a request and leaves the
 * dialog empty forever.
 */

const HELP_BUTTON = 'button[title="راهنما"]';
const MODAL = 'dialog.modal';
// Two .space-y-6 nodes live inside the dialog (the wrapper + the content
// file's own root); pin the wrapper so the locator stays unambiguous.
const MODAL_BODY = 'dialog.modal .space-y-6.text-right';
const CLOSE_ICON = 'dialog.modal .modal-box > form[method="dialog"] button';

test.describe('help modal', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('opens with the dashboard body and reopens after closing', async ({ page }) => {
    await page.goto('/dashboard');

    // #1166: the body is not shipped while the modal is closed.
    await expect(page.locator(MODAL_BODY)).toHaveCount(0);

    await page.locator(HELP_BUTTON).first().click();
    await expect(page.locator(MODAL)).toHaveClass(/modal-open/);
    await expect(page.locator(MODAL_BODY)).toBeVisible();
    await expect(page.locator(MODAL_BODY)).toContainText('داشبورد مدیریت اطلاعات سلامت');

    // ✕ closes client-side; the dialog must actually close.
    await page.locator(CLOSE_ICON).first().click();
    await expect(page.locator(MODAL)).not.toHaveClass(/modal-open/);

    // Reopening must bring the body back through a fresh server round-trip.
    await page.locator(HELP_BUTTON).first().click();
    await expect(page.locator(MODAL)).toHaveClass(/modal-open/);
    await expect(page.locator(MODAL_BODY)).toBeVisible();
    await expect(page.locator(MODAL_BODY)).toContainText('داشبورد مدیریت اطلاعات سلامت');
  });

  test('shows the fallback for a section without a content file', async ({ page }) => {
    await page.goto('/dashboard');
    await page.locator(HELP_BUTTON).first().click();
    await expect(page.locator(MODAL_BODY)).toBeVisible();

    // The help-open listener lives on `document`, so dispatch there. `disease`
    // is deliberately a real route family with no help content file.
    await page.evaluate(() => {
      document.dispatchEvent(new CustomEvent('help-open', { detail: { section: 'disease' } }));
    });

    await expect(page.getByText('راهنمای این بخش در دسترس نیست')).toBeVisible();
  });

  test('follows wire:navigate and opens the target page section', async ({ page }) => {
    await page.goto('/dashboard');

    // "جستجوی کلی" is a top-level sidebar link, so it is visible without
    // expanding a submenu; it still exercises the wire:navigate swap.
    // `hasText` skips the two mobile-only /search links in the header.
    await page.locator('a[href="/search"][wire\\:navigate]', { hasText: 'جستجوی کلی' }).click();
    await page.waitForURL('**/search');

    await page.locator(HELP_BUTTON).first().click();
    await expect(page.locator(MODAL_BODY)).toBeVisible();
    await expect(page.locator(MODAL_BODY)).toContainText('جستجوی کلی');
  });
});
