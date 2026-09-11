import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 005 — Tickets monitoring
 * Probed DOM facts:
 * - Headers: شناسه | فرستنده | واحد مقصد | وضعیت | انتظار | موضوع | جزئیات
 * - Filter tabs: همه / انتظار / انجام / تکمیل
 * - Waiting column shows Persian durations e.g. "5 روز و 20 ساعت"
 */

test.describe('tickets monitoring', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/monitoring');
    await page.waitForLoadState('networkidle');
  });

  test('monitoring loads all tickets table', async ({ page }) => {
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['شناسه', 'فرستنده', 'واحد مقصد', 'وضعیت', 'انتظار', 'موضوع']) {
      expect(headers).toContain(col);
    }
    expect(await page.locator('table tbody tr').count()).toBeGreaterThan(0);
  });

  test('filter tabs switch status filter', async ({ page }) => {
    for (const label of ['همه', 'انتظار', 'انجام', 'تکمیل']) {
      await page.getByRole('button', { name: label, exact: true }).first().click();
      await page.waitForTimeout(800);
      await expect(page.locator('table').first()).toBeVisible();
    }
  });

  test('waiting time column shows duration', async ({ page }) => {
    // The انتظار column renders Persian durations ("X روز و Y ساعت" / "X ساعت").
    const cells = await page.locator('table tbody tr').evaluateAll((rows) =>
      rows.map((r) => r.textContent || ''),
    );
    const hasDuration = cells.some((t) => /روز|ساعت|دقیقه/.test(t));
    expect(hasDuration).toBe(true);
  });
});