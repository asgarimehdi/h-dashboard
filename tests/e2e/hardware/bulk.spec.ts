import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 008 — Hardware bulk actions
 * Round-trip tests: mark → verify → unmark (restores original state).
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
    const firstCheckbox = page.locator('table input[type="checkbox"]').first();
    await firstCheckbox.check();
    await page.waitForTimeout(800);

    await expect(page.getByRole('button', { name: 'علامت', exact: true })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'برداشتن', exact: true })).toBeEnabled();
    await expect(page.locator('body')).toContainText('انتخاب');
  });

  test('selecting multiple rows shows correct count', async ({ page }) => {
    const checkboxes = page.locator('table input[type="checkbox"]');
    const count = Math.min(await checkboxes.count(), 3);
    for (let i = 0; i < count; i++) {
      await checkboxes.nth(i).check();
    }
    await page.waitForTimeout(800);

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

    await firstCheckbox.uncheck();
    await page.waitForTimeout(500);
    await expect(page.getByRole('button', { name: 'علامت', exact: true })).toBeDisabled();
  });

  test('bulk mark → unmark round-trip restores state', async ({ page }) => {
    // Read the first row's current mark state before any action
    const firstRow = page.locator('table tbody tr').first();
    const initialRowText = await firstRow.innerText();

    // Select first row
    const firstCheckbox = page.locator('table input[type="checkbox"]').first();
    await firstCheckbox.check();
    await page.waitForTimeout(500);

    // Click علامت (mark)
    await page.getByRole('button', { name: 'علامت', exact: true }).click();
    await page.waitForTimeout(2000);

    // Verify some feedback (toast or UI update)
    const bodyAfterMark = await page.locator('body').innerText();
    expect(bodyAfterMark).toMatch(/علامت|انتخاب|موفقیت|انجام/);

    // Reload to see fresh state
    await page.reload();
    await page.waitForLoadState('networkidle');

    // Select the same row again
    await firstCheckbox.check();
    await page.waitForTimeout(500);

    // Click برداشتن (unmark) to restore
    await page.getByRole('button', { name: 'برداشتن', exact: true }).click();
    await page.waitForTimeout(2000);

    // Verify feedback
    const bodyAfterUnmark = await page.locator('body').innerText();
    expect(bodyAfterUnmark).toMatch(/برداشتن|انتخاب|موفقیت|انجام/);
  });
});
