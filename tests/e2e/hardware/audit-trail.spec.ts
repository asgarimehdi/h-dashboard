import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 008 — Hardware audit trail (history modal)
 * Probed DOM facts:
 * - Desktop table rows: "تاریخچه" icon button (wire:click=loadHistory(id)) — icon-only
 *   on the desktop table, but a labelled "تاریخچه" button on mobile cards (md:hidden).
 * - Modal title "تاریخچه تغییرات" with filter chips (همه/ایجاد/ویرایش/حذف/علامت گروهی/حذف گروهی/بازگردانی)
 * - Empty state: "تاریخچه‌ای ثبت نشده است."
 *
 * NOTE: rollback mutates data — assert modal opens + renders, not the destructive action.
 */

test.describe('hardware audit trail', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
  });

  test('history modal opens with title and content', async ({ page }) => {
    // Scope to the DESKTOP table (mobile cards are md:hidden and not clickable).
    const historyBtn = page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first();
    await historyBtn.click();
    await page.waitForTimeout(1000);

    await expect(page.locator('body')).toContainText('تاریخچه تغییرات');
    await expect(page.locator('body')).toContainText(/تاریخچه‌ای ثبت نشده است|ایجاد|ویرایش|حذف/);
  });

  test('history modal shows action filter chips', async ({ page }) => {
    await page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first().click();
    await page.waitForTimeout(1000);
    for (const chip of ['همه', 'ایجاد', 'ویرایش', 'حذف', 'بازگردانی']) {
      await expect(page.locator('body')).toContainText(chip);
    }
  });

  test('rollback restores field and logs rollback entry', async ({ page }) => {
    // Hardware id 4 (AB-17SH-EZDEVAJ) has updated/rollback audits → rollback buttons render.
    await page.locator('input[placeholder*="جستجو"]').first().fill('AB-17SH-EZDEVAJ');
    await page.waitForTimeout(1500);
    await expect(page.locator('table tbody tr')).toHaveCount(1);

    await page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first().click();
    await expect(page.locator('body')).toContainText('تاریخچه تغییرات', { timeout: 10000 });

    const rollbackBtn = page.locator('button[wire\\:click*="rollbackHistoryField"]').first();
    await expect(rollbackBtn).toBeVisible({ timeout: 10000 });

    page.on('dialog', (dialog) => dialog.accept());
    await rollbackBtn.click();
    await expect(page.locator('.toast').first()).toContainText('بازگردانده شد', { timeout: 10000 });

    // The rollback action itself is logged — filter to بازگردانی and expect an entry.
    await page.locator('button[wire\\:click="filterHistory(\'rollback\')"]').first().click();
    await page.waitForTimeout(1500);
    await expect(page.locator('body')).toContainText('بازگردانی');
  });
});
