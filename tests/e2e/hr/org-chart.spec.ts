import { test, expect, login } from '../shared/fixtures';

/**
 * HR org chart (/hr/org-chart) — issue #704.
 *
 * Before #704 this page implemented its own tree; it now composes the
 * reusable <livewire:unit.tree> component and fills a detail panel on the
 * `unit-selected` event. Probed DOM facts:
 * - Header text "چارت سازمانی"
 * - Tree renders inside .tree-container with .tree-node-dot guide markers
 * - The tree owns the search box (placeholder "جستجوی واحد...") and the
 *   expand/collapse buttons, because that state lives on the child component
 * - Node click targets are wire:click="selectNode(id)" (dispatches unit-selected)
 * - Each node carries the personnel badge ("نفر") injected as badgeView
 * - Unselected state shows "یک واحد را انتخاب کنید"
 */

test.describe('hr org chart', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hr/org-chart');
    await page.waitForLoadState('networkidle');
  });

  test('page and tree render', async ({ page }) => {
    await expect(page.locator('body')).toContainText('چارت سازمانی');
    await expect(page.locator('.tree-container')).toBeVisible();
    expect(await page.locator('.tree-node-dot').count()).toBeGreaterThan(0);
  });

  test('tree search input is present', async ({ page }) => {
    await expect(page.locator('input[placeholder^="جستجوی واحد"]')).toBeVisible();
  });

  test('expand and collapse buttons are present', async ({ page }) => {
    await expect(page.locator('button, [wire\\:click], a').filter({ hasText: 'باز کردن همه' }).first()).toBeVisible();
    await expect(page.locator('button, [wire\\:click], a').filter({ hasText: 'جمع کردن' }).first()).toBeVisible();
  });

  test('personnel badge renders on nodes', async ({ page }) => {
    // badgeView="livewire.hr.personnel-badge" — the plug-in contract (#704).
    await expect(page.locator('body')).toContainText('نفر');
  });

  test('empty selection placeholder, then selecting a node fills the detail panel', async ({ page }) => {
    await expect(page.locator('body')).toContainText('یک واحد را انتخاب کنید');

    const node = page.locator('.tree-container [wire\\:click*="selectNode"]').first();
    await node.click();

    // The tree dispatched unit-selected; the page's #[On] handler filled the panel.
    await expect(page.locator('body')).toContainText(/پرسنل مستقیم|کاربران مستقیم/);
  });

  test('search highlights the match and keeps its ancestor chain expanded', async ({ page }) => {
    // Seed-real term: the tree contains "شبکه بهداشت و درمان طارم". The first
    // assertion is the discriminator — .border-primary on a node only appears
    // when updatedSearch() ran and marked the match ($isMatch), so a search
    // that never fires (dead wire:model, wrong component) fails here.
    const search = page.locator('input[placeholder^="جستجوی واحد"]');
    await search.fill('طارم');

    // Debounced (300ms) Livewire update: wait for the tree to settle.
    await page.waitForTimeout(1500);

    await expect(page.locator('.tree-container .border-primary').first()).toBeVisible();
    // The deep match renders, i.e. its ancestor chain was expanded too.
    await expect(page.locator('.tree-container')).toContainText('شبکه بهداشت و درمان طارم');
  });

  test('search with no match keeps the page usable', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجوی واحد"]');
    await search.fill('این‌نام‌واحدی_وجود_ندارد');
    await page.waitForTimeout(1200);

    // Page still renders the tree card rather than erroring out.
    await expect(page.locator('.tree-container')).toBeVisible();
    await expect(page.locator('body')).toContainText('چارت سازمانی');
  });

  test('collapse all empties the guide markers, expand all restores them', async ({ page }) => {
    const collapse = page.locator('button, [wire\\:click], a').filter({ hasText: 'جمع کردن' }).first();
    const expand = page.locator('button, [wire\\:click], a').filter({ hasText: 'باز کردن همه' }).first();

    await collapse.click();
    await page.waitForTimeout(1000);
    const afterCollapse = await page.locator('.tree-node-dot').count();

    await expand.click();
    await page.waitForTimeout(1000);
    const afterExpand = await page.locator('.tree-node-dot').count();

    // Collapsing drops the nested guide dots (root nodes stay visible);
    // expanding brings the deeper levels back.
    expect(afterExpand).toBeGreaterThan(afterCollapse);
  });
});
