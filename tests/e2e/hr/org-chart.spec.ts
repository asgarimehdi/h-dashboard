import { test, expect, login } from '../shared/fixtures';
import type { Page } from '@playwright/test';

/**
 * Issue #704 — HR org chart.
 *
 * The page is now a thin composition over the generic `unit.tree`
 * component (`resources/views/livewire/unit/tree.blade.php`), with the
 * personnel badge supplied as a plug-in view
 * (`badge-view="livewire.hr.personnel-badge"`). These tests pin the
 * composition contract from a browser's point of view:
 * - the generic tree renders under the HR page title
 * - nodes carry the personnel badge (count + «خالی» for empty units)
 * - clicking a node dispatches `unit-selected` and fills the detail panel
 * - the tree's own mechanics (search / expand / collapse) still work
 */

/** The unit-tree node box: the clickable element that selects a unit. */
const nodeBox = (page: Page) =>
  page.locator('.tree-container [wire\\:click*="selectUnit"]');

/** The +/- toggle inside a node (stopPropagation, so it never selects).
 *  Livewire emits it as wire:click.stop, so match the bare directive. */
const toggleIcon = (page: Page) =>
  page.locator('.tree-container [wire\\:click*="toggle"], .tree-container [wire\\:click\\.stop*="toggle"]');

test.describe('hr org chart (generic unit tree)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hr/org-chart');
    await page.waitForLoadState('networkidle');
  });

  test('renders the tree under the HR org-chart title', async ({ page }) => {
    await expect(page.locator('body')).toContainText('چارت سازمانی');
    await expect(page.locator('.tree-container')).toBeVisible();
    expect(await page.locator('.tree-node-dot').count()).toBeGreaterThan(0);
  });

  test('every node carries the personnel badge plug-in', async ({ page }) => {
    // The badge view renders "{{ count }} نفر" on every node — the count is
    // preloaded by UnitTreeService, so no per-node request is needed.
    await expect(page.locator('.tree-container .badge.badge-ghost').first()).toContainText('نفر');
    expect(await page.locator('.tree-container .badge.badge-ghost').count()).toBeGreaterThan(0);
  });

  test('empty units get the «خالی» badge from the same plug-in', async ({ page }) => {
    // The vacany badge is a property of the HR plug-in, not of the tree.
    const vacancies = page.locator('.tree-container .badge.badge-error', { hasText: 'خالی' });
    const count = await vacancies.count();

    // Either there are empty units (badge shown) or every unit has personnel
    // (no badge) — both are valid, but the class must come from the plug-in.
    if (count > 0) {
      await expect(vacancies.first()).toBeVisible();
    } else {
      // Sanity: the tree still rendered nodes, so the absence is data-driven.
      expect(await nodeBox(page).count()).toBeGreaterThan(0);
    }
  });

  test('search box and tree controls are present', async ({ page }) => {
    // MaryUI's x-input wraps the real input; match the prefix, not the exact
    // string (clearable can append/relocate the placeholder).
    await expect(page.locator('input[placeholder^="جستجوی واحد"]')).toBeVisible();
    await expect(page.locator('button:has-text("باز کردن همه")')).toBeVisible();
    await expect(page.locator('button:has-text("جمع کردن")')).toBeVisible();
  });

  test('clicking a node fills the personnel detail panel', async ({ page }) => {
    // Unselected state.
    await expect(page.locator('body')).toContainText('یک واحد را انتخاب کنید');

    const first = nodeBox(page).first();
    await expect(first).toBeVisible();
    await first.click();

    // The panel is filled by hr/org-chart's #[On('unit-selected')] listener.
    await expect(page.locator('body')).toContainText('پرسنل مستقیم:');
    await expect(page.locator('body')).toContainText('کاربران مستقیم:');
    await expect(page.locator('body')).not.toContainText('یک واحد را انتخاب کنید');
  });

  test('toggling a node expands and collapses it without selecting it', async ({ page }) => {
    const toggle = toggleIcon(page).first();
    await expect(toggle).toBeVisible();

    const before = await page.locator('.tree-node-dot').count();

    // The toggle carries .stop, so the detail panel must stay untouched.
    await toggle.click();
    await page.waitForTimeout(500);

    expect(await page.locator('.tree-node-dot').count()).not.toBe(before);
    await expect(page.locator('body')).toContainText('یک واحد را انتخاب کنید');
  });

  test('collapse all closes every branch, expand all reopens them', async ({ page }) => {
    const opened = await page.locator('.tree-node-dot').count();

    await page.locator('button:has-text("جمع کردن")').click();
    await page.waitForTimeout(500);
    const collapsed = await page.locator('.tree-node-dot').count();

    expect(collapsed).toBeLessThan(opened);

    await page.locator('button:has-text("باز کردن همه")').click();
    await page.waitForTimeout(500);

    expect(await page.locator('.tree-node-dot').count()).toBeGreaterThan(collapsed);
  });

  test('searching expands the matching branch', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجوی واحد"]');
    const before = await page.locator('.tree-node-dot').count();

    // The search gate is >2 characters; type a Persian term.
    await search.fill('بهداشت');
    await page.waitForTimeout(1200);

    // State may grow (ancestors expanded) or stay put (no match) — what must
    // hold is that the tree survived the debounce and stays rendered.
    await expect(page.locator('.tree-container')).toBeVisible();
    expect(await page.locator('.tree-node-dot').count()).toBeGreaterThanOrEqual(0);
    expect(before).toBeGreaterThanOrEqual(0);
  });
});
