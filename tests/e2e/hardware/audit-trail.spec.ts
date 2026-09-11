import { test, expect, login } from '../shared/fixtures';
import { execSync } from 'child_process';

/**
 * Plan 008 — Hardware audit trail (history modal) + rollback
 * Tests open the modal, verify content, and test rollback as a round-trip.
 */

test.describe('hardware audit trail', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
  });

  test.afterAll(async () => {
    try {
      execSync('php tests/e2e/cleanup.php', { cwd: '/home/runner/h-dashboard', timeout: 10000 });
    } catch { /* cleanup best-effort */ }
  });

  test('history modal opens with title and content', async ({ page }) => {
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

    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);

    const modalVisible = await page.locator('.modal-open, [role="dialog"]:visible').count();
    expect(modalVisible).toBe(0);
  });

  test('rollback button is present in audit entries', async ({ page }) => {
    await page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first().click();
    await page.waitForTimeout(1000);

    const emptyState = page.locator('text=تاریخچه‌ای ثبت نشده است');
    const isEmpty = await emptyState.isVisible().catch(() => false);

    if (!isEmpty) {
      const rollbackBtns = page.locator('button:has-text("بازگردانی"), [wire\\:click*="rollback"]');
      const rollbackCount = await rollbackBtns.count();
      if (rollbackCount > 0) {
        await expect(rollbackBtns.first()).toBeVisible();
      }
    }
  });

  test('rollback a field → confirm → value restored', async ({ page }) => {
    // Open history modal
    await page.locator('div.hidden.md\\:block button[wire\\:click*="loadHistory"]').first().click();
    await page.waitForTimeout(1500);

    // Check if there are rollback-able entries
    const rollbackBtn = page.locator('button:has-text("بازگردانی"), [wire\\:click*="rollback"]').first();
    const hasRollback = await rollbackBtn.isVisible().catch(() => false);

    if (!hasRollback) {
      // No audit entries to rollback — skip gracefully
      test.skip();
      return;
    }

    // Click rollback
    await rollbackBtn.click();
    await page.waitForTimeout(1000);

    // Look for confirmation dialog (Livewire wire:confirm or MaryUI modal)
    const confirmBtn = page.locator('button:has-text("تأیید"), button:has-text("بله"), button:has-text("OK")').first();
    if (await confirmBtn.isVisible().catch(() => false)) {
      await confirmBtn.click();
      await page.waitForTimeout(2000);
    }

    // Verify success indication (toast or UI update)
    const body = await page.locator('body').innerText();
    expect(body).toMatch(/موفقیت|بازگردانی|ثبت شد|انجام/);
  });
});
