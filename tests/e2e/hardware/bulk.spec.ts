import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 008 — Hardware bulk actions (non-destructive)
 *
 * Selecting rows (checkbox → wire:model.live="selected") enables the bulk toolbar
 * buttons علامت/برداشتن/حذف. We verify the enable/disable state machine and the
 * selection count chip WITHOUT persisting a mutation (bulk-mark would flip `mark`
 * on real records, so we only assert the UI reacts to selection — the actual
 * destructive/stateful actions are covered by backend tests).
 */

test.describe('hardware bulk actions', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
  });

  test('bulk buttons are disabled until a row is selected', async ({ page }) => {
    const bulkMark = page.getByRole('button', { name: 'علامت', exact: true });
    const bulkDelete = page.getByRole('button', { name: 'حذف', exact: true });
    await expect(bulkMark).toBeDisabled();
    await expect(bulkDelete).toBeDisabled();
  });

  test('selecting a row enables bulk actions and shows count', async ({ page }) => {
    // Check the first row's checkbox.
    const firstCheckbox = page.locator('table input[type="checkbox"]').first();
    await firstCheckbox.check();
    await page.waitForTimeout(800);

    await expect(page.getByRole('button', { name: 'علامت', exact: true })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'برداشتن', exact: true })).toBeEnabled();
    // Selection count chip "N انتخاب" appears.
    await expect(page.locator('body')).toContainText('انتخاب');
  });
});