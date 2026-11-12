import type { Page } from '@playwright/test';
import { test, expect, login, waitForLivewire, TEST_USER } from '../shared/fixtures';
/**
 * #1467 — Hardware edit is an inline panel, not a modal.
 *
 * The edit form used to live inside `<x-modal wire:model="showEditModal">`; on
 * short viewports the modal overflowed and its actions were unreachable. It now
 * renders inline above the table, in the same shape as the create form:
 * `#hardware-edit-panel` with a 3-column grid and its own «ذخیره»/«لغو».
 *
 * These tests pin the BEHAVIOUR, never the binding:
 *   1. opening edit renders the panel outside any dialog, with every field and
 *      both actions reachable in the viewport
 *   2. «ذخیره» really persists the change and closes the panel
 *   3. «لغو» closes the panel without writing
 */

const EDIT_BUTTON =
  'div.hidden.md\\:block button[aria-label="ویرایش سخت‌افزار"]';

const FIELD_SELECTORS = [
  '[wire\\:model="personSearch"]',
  '[wire\\:model="pc_name"]',
  '[wire\\:model="type"]',
  '[wire\\:model="os"]',
  '[wire\\:model="ip_valid"]',
  '[wire\\:model="ip_local"]',
  '[wire\\:model="mac"]',
  '[wire\\:model="net_type"]',
  '[wire\\:model="switch"]',
  '[wire\\:model="port"]',
  '[wire\\:model="vlan"]',
  '[wire\\:model="motherboard"]',
  '[wire\\:model="cpu"]',
  '[wire\\:model="ram"]',
  '[wire\\:model="hdd"]',
  '[wire\\:model="clean_at"]',
  'input[type="checkbox"][wire\\:model="shutdown"]',
  'input[type="checkbox"][wire\\:model="mark"]',
  '[wire\\:model="comments"]',
];

/** Create a dedicated row so the shared seeded data stays untouched. */
async function createRow(page: Page, pcName: string) {
  await page.getByRole('button', { name: 'افزودن سخت‌افزار' }).click();

  // MaryUI pads the placeholder with a trailing space, so anchor on the prefix.
  const nCodeInput = page.locator('input[placeholder^="جستجو..."]');
  await expect(nCodeInput).toBeVisible();
  await nCodeInput.fill(TEST_USER.nCode);
  await expect(page.locator('div.text-xs.text-success')).toBeVisible({ timeout: 10000 });

  await page.locator('input[placeholder^="PC-NAME"]').fill(pcName);
  await page.getByRole('button', { name: 'ذخیره', exact: true }).click();
  await expect(page.locator('.toast', { hasText: 'ایجاد شد' }).first()).toBeVisible({
    timeout: 10000,
  });

  // Isolate exactly this row.
  const search = waitForLivewire(page);
  await page.locator('input[placeholder*="جستجو در تمام"]').first().fill(pcName);
  await search;
  await expect(page.locator('table tbody tr')).toHaveCount(1);
}

async function openEdit(page: Page) {
  const update = waitForLivewire(page);
  await page.locator(EDIT_BUTTON).first().click();
  await update;
  await expect(page.locator('#hardware-edit-panel')).toBeVisible();
}

test.describe('hardware inline edit panel', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
  });

  test('opens inline with every field and both actions reachable', async ({ page }) => {
    const pcName = `E2E-EDIT-INLINE-${Date.now()}`;
    await createRow(page, pcName);
    await openEdit(page);

    // The panel is inline, not inside any dialog.
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await expect(page.locator('dialog #hardware-edit-panel')).toHaveCount(0);
    await expect(page.getByText('ویرایش سخت افزار')).toBeVisible();

    // Every field the edit form carries is present and visible.
    expect(FIELD_SELECTORS).toHaveLength(19);
    for (const selector of FIELD_SELECTORS) {
      await expect(page.locator(selector)).toBeVisible();
    }

    // Both actions are scrollable into the viewport — the old modal could not
    // promise this on a short viewport.
    const save = page.getByRole('button', { name: 'ذخیره', exact: true });
    await save.scrollIntoViewIfNeeded();
    await expect(save).toBeInViewport();

    const cancel = page.getByRole('button', { name: 'لغو', exact: true });
    await cancel.scrollIntoViewIfNeeded();
    await expect(cancel).toBeInViewport();
  });

  test('save persists the new name and closes the panel', async ({ page }) => {
    const oldName = `E2E-EDIT-SAVE-${Date.now()}`;
    const newName = `${oldName}-UPDATED`;
    await createRow(page, oldName);
    await openEdit(page);

    await page.locator('#hardware-edit-panel [wire\\:model="pc_name"]').fill(newName);

    const update = waitForLivewire(page);
    await page.getByRole('button', { name: 'ذخیره', exact: true }).click();
    await update;

    await expect(page.locator('.toast', { hasText: 'بروزرسانی شد' }).first()).toBeVisible({
      timeout: 10000,
    });
    await expect(page.locator('#hardware-edit-panel')).toHaveCount(0);

    const search = waitForLivewire(page);
    await page.locator('input[placeholder*="جستجو در تمام"]').first().fill(newName);
    await search;
    await expect(page.locator('table tbody tr')).toHaveCount(1);
    await expect(page.locator('table tbody')).toContainText(newName);
  });

  test('cancel closes the panel without saving', async ({ page }) => {
    const oldName = `E2E-EDIT-CANCEL-${Date.now()}`;
    await createRow(page, oldName);
    await openEdit(page);

    await page.locator('#hardware-edit-panel [wire\\:model="pc_name"]').fill(`${oldName}-DISCARDED`);

    const update = waitForLivewire(page);
    await page.getByRole('button', { name: 'لغو', exact: true }).click();
    await update;

    await expect(page.locator('#hardware-edit-panel')).toHaveCount(0);

    // The isolated row is still the original one.
    await expect(page.locator('table tbody tr')).toHaveCount(1);
    await expect(page.locator('table tbody')).toContainText(oldName);
    await expect(page.locator('table tbody')).not.toContainText(`${oldName}-DISCARDED`);
  });
});
