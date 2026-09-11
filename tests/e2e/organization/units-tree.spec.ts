import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 007 — Units tree (chart)
 * Probed DOM facts:
 * - /units/chart renders "ساختار درختی واحدها"
 * - Tree search: input[placeholder^="جستجو در واحدها"]
 * - Tree nodes render .tree-node-dot markers; root units load expanded to 3 levels
 * - Unselected state shows "یک واحد را انتخاب کنید"; clicking a node loads detail panel
 */

test.describe('units tree (chart)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/units/chart');
    await page.waitForLoadState('networkidle');
  });

  test('tree view renders hierarchy', async ({ page }) => {
    await expect(page.locator('body')).toContainText('ساختار درختی واحدها');
    await expect(page.locator('.tree-container')).toBeVisible();
    // root units + descendants render node markers
    expect(await page.locator('.tree-node-dot').count()).toBeGreaterThan(0);
  });

  test('empty selection shows placeholder, then click loads details', async ({ page }) => {
    await expect(page.locator('body')).toContainText('یک واحد را انتخاب کنید');

    // Click the first unit box (the clickable node showing a unit name).
    const node = page.locator('.tree-container [wire\\:click*="selectUnit"]').first();
    await node.click();
    await page.waitForTimeout(1000);

    // Detail panel now shows the selected unit's ‌"نوع"/"کاربران" fields.
    await expect(page.locator('body')).toContainText(/نوع:|کاربران:/);
  });

  test('tree search input is present', async ({ page }) => {
    await expect(page.locator('input[placeholder^="جستجو در واحدها"]')).toBeVisible();
  });
});