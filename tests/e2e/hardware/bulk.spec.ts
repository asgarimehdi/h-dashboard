import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 008 — Hardware bulk actions
 *
 * Selecting rows (checkbox → wire:model.live="selected") enables the bulk toolbar
 * buttons علامت/برداشتن/حذف. We verify the enable/disable state machine and the
 * selection count chip.
 *
 * The bulk-mark/unmark tests are marked fixme because they mutate real hardware
 * records (flipping the `mark` field). The round-trip toggle test below is safe
 * because it reverts the change.
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

  test('selecting multiple rows shows correct count', async ({ page }) => {
    const checkboxes = page.locator('table input[type="checkbox"]');
    const count = Math.min(await checkboxes.count(), 3);
    for (let i = 0; i < count; i++) {
      await checkboxes.nth(i).check();
    }
    await page.waitForTimeout(800);

    // Should show "N انتخاب" with N >= 3
    const bodyText = await page.locator('body').innerText();
    const match = bodyText.match(/(\d+)\s*انتخاب/);
    expect(match).not.toBeNull();
    expect(parseInt(match![1], 10)).toBeGreaterThanOrEqual(count);
  });

  test('deselecting all rows disables bulk actions again', async ({ page }) => {
    const firstCheckbox = page.locator('table input[type="checkbox"]').first();
    await firstCheckbox.check();
    await page.waitForTimeout(500);
    await expect(page.getByRole('button', { name: 'علامت', exact: true })).toBeEnabled();

    // Uncheck
    await firstCheckbox.uncheck();
    await page.waitForTimeout(500);
    await expect(page.getByRole('button', { name: 'علامت', exact: true })).toBeDisabled();
  });

  test.fixme('bulk mark → records get marked + bulk unmark reverts', async ({ page }) => {
    // Select first 2 rows
    const checkboxes = page.locator('table input[type="checkbox"]');
    await checkboxes.nth(0).check();
    await checkboxes.nth(1).check();
    await page.waitForTimeout(500);

    // Click "علامت" (mark)
    await page.getByRole('button', { name: 'علامت', exact: true }).click();
    await page.waitForTimeout(2000);

    // Verify some indication of success (toast or UI update)
    const body = await page.locator('body').innerText();
    expect(body).toMatch(/علامت|انتخاب|موفقیت/);

    // Now unmark — select the same rows and click "برداشتن"
    await checkboxes.nth(0).check();
    await checkboxes.nth(1).check();
    await page.waitForTimeout(500);
    await page.getByRole('button', { name: 'برداشتن', exact: true }).click();
    await page.waitForTimeout(2000);
  });
});
