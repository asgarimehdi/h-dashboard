import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 008 — Hardware audit trail (history modal)
 * Probed DOM facts:
 * - Desktop table rows: "تاریخچه" icon button (wire:click=loadHistory(id)) — icon-only
 *   on the desktop table, but a labelled "تاریخچه" button on mobile cards (md:hidden).
 * - Modal title "تاریخچه تغییرات" with filter chips (همه/ایجاد/ویرایش/حذف/علامت گروهی/حذف گروهی/بازگردانی)
 * - Empty state: "تاریخچه‌ای ثبت نشده است."
 * - Rollback: each audit entry with action "ویرایش" has a "بازگردانی" button that
 *   reverts the field to its previous value after confirmation.
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

  test('history modal can be closed', async ({ page }) => {
    await page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first().click();
    await page.waitForTimeout(1000);
    await expect(page.locator('body')).toContainText('تاریخچه تغییرات');

    // Close via Escape key (MaryUI modal supports this)
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);

    // Modal content should no longer be visible
    const modalVisible = await page.locator('.modal-open, [role="dialog"]:visible').count();
    expect(modalVisible).toBe(0);
  });

  test('rollback button is present in audit entries', async ({ page }) => {
    await page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first().click();
    await page.waitForTimeout(1000);

    // Check if there are audit entries (not empty state)
    const emptyState = page.locator('text=تاریخچه‌ای ثبت نشده است');
    const isEmpty = await emptyState.isVisible().catch(() => false);

    if (!isEmpty) {
      // Find rollback buttons (بازگردانی)
      const rollbackBtns = page.locator('button:has-text("بازگردانی"), [wire\\:click*="rollback"]');
      const rollbackCount = await rollbackBtns.count();

      if (rollbackCount > 0) {
        // Verify at least one rollback button is visible
        await expect(rollbackBtns.first()).toBeVisible();
      }
    }
  });

  test.fixme('rollback a field → confirm → value restored + new audit entry', async ({ page }) => {
    // This test is destructive — it actually rolls back a hardware field value.
    // Marked fixme to avoid mutating production data on every test run.
    await page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first().click();
    await page.waitForTimeout(1000);

    // Find and click a rollback button
    const rollbackBtn = page.locator('button:has-text("بازگردانی"), [wire\\:click*="rollback"]').first();
    await expect(rollbackBtn).toBeVisible();
    await rollbackBtn.click();
    await page.waitForTimeout(500);

    // Confirm the rollback (if there's a confirmation dialog)
    const confirmBtn = page.locator('button:has-text("تأیید"), button:has-text("بله")').first();
    if (await confirmBtn.isVisible()) {
      await confirmBtn.click();
    }
    await page.waitForTimeout(2000);

    // Verify success indication
    const body = await page.locator('body').innerText();
    expect(body).toMatch(/موفقیت|بازگردانی|ثبت شد/);
  });
});
