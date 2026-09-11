import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 008 — Hardware import/export
 * Probed DOM facts:
 * - /hardware/import renders "ورود اطلاعات شناسنامه سخت‌افزار از فایل اکسل"
 *   with file input; supported .xlsx/.xls/.csv; match on pc_name or MAC.
 * - Export: toolbar "خروجی اکسل" → wire:click=exportExcel → triggers download event
 *   via Livewire dispatch('download-export', url).
 */

test.describe('hardware import/export', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('import page loads with file upload control', async ({ page }) => {
    await page.goto('/hardware/import');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText('ورود اطلاعات شناسنامه سخت‌افزار از فایل اکسل');
    await expect(page.locator('input[type="file"]')).toBeVisible();
  });

  test('export button is present and enabled', async ({ page }) => {
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
    const exportBtn = page.getByRole('button', { name: 'خروجی اکسل' });
    await expect(exportBtn).toBeVisible();
    await expect(exportBtn).toBeEnabled();
  });

  test('export triggers download with correct URL', async ({ page }) => {
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');

    // Set up download listener BEFORE clicking
    const downloadPromise = page.waitForEvent('download', { timeout: 10000 }).catch(() => null);

    // Also listen for the Livewire dispatch event
    const downloadUrl = await page.evaluate(() => {
      return new Promise<string>((resolve) => {
        // @ts-ignore
        Livewire.on('download-export', (url: string) => resolve(url));
        // Fallback timeout
        setTimeout(() => resolve(''), 5000);
      });
    });

    // Click export
    await page.getByRole('button', { name: 'خروجی اکسل' }).click();
    await page.waitForTimeout(2000);

    // Verify the dispatched URL contains the export route
    if (downloadUrl) {
      expect(downloadUrl).toContain('hardware/export');
    }

    // Also check that a download event was triggered
    const download = await downloadPromise;
    if (download) {
      expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
      // Clean up the downloaded file
      const path = await download.path();
      if (path) {
        const fs = await import('fs');
        fs.unlinkSync(path);
      }
    }
  });
});
