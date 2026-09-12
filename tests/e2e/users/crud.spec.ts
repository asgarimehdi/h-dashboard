import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 004 — Users CRUD via the inline modal on /users (users.index).
 *
 * History: the standalone routes /users/create and /users/{id}/edit used to
 * exist but pointed at `users.create` / `users.edit` views that were never
 * implemented (HTTP 500). The dead routes were removed; create/edit live in
 * the inline modal of users.index (openFormForCreate / edit). This spec
 * covers the real flows:
 * - create modal renders all fields
 * - create with a duplicate n_code → validation error (no mutation)
 * - edit opens with prefilled values, closes without saving (no mutation)
 * - delete → dismiss confirm → user kept (no mutation)
 * - delete → accept → soft-deleted → restore → active again (mutates + reverts
 *   in the same test, so the suite stays repeatable)
 * - removed standalone routes return 404, not 500
 *
 * Probed DOM facts:
 * - open create: button[wire:click="openFormForCreate"] → "ثبت کاربر جدید"
 * - person search: input[wire:model.live.debounce.500ms="person_search"] →
 *   suggestion divs with wire:click="selectPerson('n_code')"
 * - password: input[wire:model="password"][type="password"]
 * - submit: x-form wire:submit → createUser/updateUser; button "ذخیره"
 * - edit: button[wire:click^="edit("] per row → "ویرایش کاربر" + prefilled search
 * - delete: button[wire:click^="delete("] (wire:confirm native dialog)
 * - restore: button[wire:click^="restore("] visible under filter "غیرفعال"
 */

test.describe('users CRUD (inline modal)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/users');
    await page.waitForLoadState('networkidle');
  });

  test('standalone create/edit routes are gone (404, not 500)', async ({ page }) => {
    for (const path of ['/users/create', '/users/4/edit']) {
      const resp = await page.request.get(path);
      expect(resp.status(), `${path} should be 404`).toBe(404);
    }
  });

  test('open create modal renders the full form', async ({ page }) => {
    await page.locator('button[wire\\:click="openFormForCreate"]').click();
    // Wait for the modal content to appear (reactive check instead of arbitrary timeout)
    await expect(page.locator('body')).toContainText('ثبت کاربر جدید', { timeout: 10000 });

    await expect(page.locator('input[wire\\:model\\.live\\.debounce\\.500ms="person_search"]')).toBeVisible();
    await expect(page.locator('input[wire\\:model="password"]')).toBeVisible();
    await expect(page.locator('body')).toContainText('نقش‌ها');
    await expect(page.locator('body')).toContainText('واحدها');
    await expect(page.getByRole('button', { name: 'ذخیره' })).toBeVisible();
  });

  test('create with duplicate n_code shows validation error', async ({ page }) => {
    await page.locator('button[wire\\:click="openFormForCreate"]').click();
    // Wait for the modal to open before interacting
    await expect(page.locator('body')).toContainText('ثبت کاربر جدید', { timeout: 10000 });

    // Pick a person that already has a user account.
    const search = page.locator('input[wire\\:model\\.live\\.debounce\\.500ms="person_search"]');
    await search.fill('هادیلو');
    // Wait for search results dropdown to appear (reactive instead of waitForTimeout)
    await page.waitForSelector('div.max-h-40 div.p-2', { state: 'visible', timeout: 10000 });
    await page.locator('div.max-h-40 div.p-2', { hasText: 'مهدی هادیلو' }).first().click();
    // Wait for Livewire to process the person selection
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 5000 });

    await page.locator('input[wire\\:model="password"]').fill('12345678');
    await page.getByRole('button', { name: 'ذخیره' }).click();
    // Keep waitForTimeout here: dialog/submit response has no reliable DOM signal for validation errors
    // that appear asynchronously after form submission
    await page.waitForTimeout(1200);

    await expect(page.locator('body')).toContainText('این کد ملی قبلاً ثبت شده است');
  });

  test('edit opens prefilled and closes without saving', async ({ page }) => {
    // n_code of the first row, to prove nothing changed afterwards.
    const firstRow = page.locator('table tbody tr').first();
    const before = await firstRow.innerText();

    await page.locator('button[wire\\:click^="edit("]').first().click();
    // Wait for edit modal text to appear (reactive instead of arbitrary timeout)
    await expect(page.locator('body')).toContainText('ویرایش کاربر', { timeout: 10000 });
    const searchValue = await page
      .locator('input[wire\\:model\\.live\\.debounce\\.500ms="person_search"]')
      .inputValue();
    expect(searchValue.length).toBeGreaterThan(0);

    // Close without saving (the X button calls resetForm).
    await page.locator('button[wire\\:click="resetForm"]').first().click();
    // Wait for modal to close (reactive instead of arbitrary timeout)
    await expect(page.locator('body')).not.toContainText('ویرایش کاربر', { timeout: 5000 });

    // Table unchanged.
    await expect(page.locator('table tbody tr').first()).toContainText(
      before.split('\n')[0].trim().slice(0, 20),
    );
  });

  test('delete → dismiss confirm keeps the user', async ({ page }) => {
    const firstCode = await page.locator('table tbody tr').first().innerText();

    page.on('dialog', (dialog) => dialog.dismiss());
    await page.locator('button[wire\\:click^="delete("]').first().click();
    // Keep waitForTimeout: dialog dismiss + Livewire refresh has no single reliable DOM signal
    await page.waitForTimeout(1200);

    // Still in the active list.
    await expect(page.locator('table tbody')).toContainText(
      firstCode.split('\n').find((l) => /\d{10}/.test(l))!.trim(),
    );
  });

  test('delete → accept soft-deletes, restore brings the user back', async ({ page }) => {
    // Grab an identifier from the first row (n_code, 10 digits).
    const firstRowText = await page.locator('table tbody tr').first().innerText();
    const nCodeMatch = firstRowText.match(/\d{10}/);
    expect(nCodeMatch, `first row should contain an n_code, got: ${firstRowText.slice(0, 120)}`).not.toBeNull();
    const nCode: string = nCodeMatch![0];

    // Accept the native confirm → soft delete.
    page.on('dialog', (dialog) => dialog.accept());
    await page.locator('button[wire\\:click^="delete("]').first().click();
    // Wait for Livewire to process the deletion
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('table tbody')).not.toContainText(nCode);

    // Switch to the inactive filter — the user must be there now.
    await page.locator('select.select-bordered').selectOption('inactive');
    // Wait for Livewire filter to complete
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('table tbody')).toContainText(nCode);

    // Restore → back to active (reverts the mutation in the same test).
    await page.locator('button[wire\\:click^="restore("]').first().click();
    // Wait for Livewire to process the restore
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });

    await page.locator('select.select-bordered').selectOption('active');
    // Wait for Livewire filter to complete
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('table tbody')).toContainText(nCode);
  });
});
