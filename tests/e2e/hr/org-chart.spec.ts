import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #704 — HR org chart page is a thin composition over the reusable
 * `unit.tree` component (tree mechanics live in the child, panel in the page).
 *
 * Probed DOM facts (seed data: 784 units, single root «وزارت بهداشت»,
 * hierarchy 4+ levels deep):
 * - page header renders «چارت سازمانی», detail placeholder «یک واحد را انتخاب کنید»
 * - tree controls: input[placeholder="جستجوی واحد..."], wire:click buttons
 *   collapseAll («جمع کردن») / expandAll («باز کردن همه»)
 * - nodes: .tree-container [wire\:click*="selectUnit"] — clicking dispatches
 *   `unit-selected`, which the PAGE listens to (#[On('unit-selected')]) and
 *   fills «نوع:» / «پرسنل مستقیم:» in the side panel
 * - search matches render with .text-primary; badge slot shows «N نفر» or «خالی»
 */

const NODE = '.tree-container [wire\\:click*="selectUnit"]';
// MaryUI's clearable input appends a space to the placeholder → match by prefix
const SEARCH_INPUT = 'input[placeholder^="جستجوی واحد"]';
const COLLAPSE = 'button[wire\\:click="collapseAll"]';
const EXPAND = 'button[wire\\:click="expandAll"]';

test.describe('HR org chart (unit.tree composition)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hr/org-chart');
    await page.waitForLoadState('networkidle');
  });

  test('renders the reusable tree with controls and badges', async ({ page }) => {
    await expect(page.locator('body')).toContainText('چارت سازمانی');
    await expect(page.locator('.tree-container')).toBeVisible();
    await expect(page.locator(SEARCH_INPUT)).toBeVisible();
    await expect(page.locator(COLLAPSE)).toBeVisible();
    await expect(page.locator(EXPAND)).toBeVisible();

    // 3 levels are expanded by default → more than the single seeded root
    expect(await page.locator(NODE).count()).toBeGreaterThan(1);
    // badge slot is fed by the page (badge-data) — either count or «خالی»
    await expect(page.locator('body')).toContainText(/نفر|خالی/);
  });

  test('clicking a node fires unit-selected and fills the detail panel', async ({
    page,
  }) => {
    await expect(page.locator('body')).toContainText('یک واحد را انتخاب کنید');

    await page.locator(NODE).first().click();

    await expect(page.locator('body')).toContainText(/نوع:|پرسنل مستقیم:/);
  });

  test('collapseAll hides descendants, expandAll reveals them again', async ({
    page,
  }) => {
    const boxes = page.locator(NODE);
    const byDefault = await boxes.count();

    await page.locator(COLLAPSE).click();
    await expect.poll(() => boxes.count(), { timeout: 15000 }).toBeLessThan(byDefault);
    const collapsed = await boxes.count();

    await page.locator(EXPAND).click();
    await expect.poll(() => boxes.count(), { timeout: 15000 }).toBeGreaterThan(collapsed);
  });

  test('search expands and highlights its matches', async ({ page }) => {
    await page.locator(SEARCH_INPUT).fill('شبکه');

    // a level-4 unit is pulled into the default 3-level view by the search
    await expect(page.locator('body')).toContainText('شبکه بهداشت و درمان ابهر');
    await expect(page.locator('.tree-container .text-primary').first()).toBeVisible();

    const highlighted = await page.locator('.tree-container .text-primary').allInnerTexts();
    expect(highlighted.length).toBeGreaterThan(0);
    for (const text of highlighted) {
      expect(text).toContain('شبکه');
    }

    // clearing the search clears the highlights too
    await page.locator(SEARCH_INPUT).fill('');
    await expect
      .poll(() => page.locator('.tree-container .text-primary').count(), { timeout: 15000 })
      .toBe(0);
  });
});
