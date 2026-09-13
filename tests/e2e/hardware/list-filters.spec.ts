import { test, expect, login } from '../shared/fixtures';
/**
 * Plan 008 — Hardware list + filters
 * Probed DOM facts:
 * - 449 devices total; desktop table columns: #/نام دستگاه/صاحب/واحد/نوع/OS/IP/CPU/RAM/HDD/وضعیت
 * - Quick presets: لپ‌تاپ‌ها(9)/سرورها/رم 16GB+/فقط SSD/روشن‌ها(شroshutdown=1)/علامت‌دارها/حذف شده‌ها
 * - Search: input[placeholder^="جستجو در تمام"]
 * - Advanced filter panel toggled by button[wire\:click*="showFilters"] → "نوع دستگاه"/"سیستم عامل"...
 * - Row checkboxes: table input[type=checkbox]; bulk buttons disabled until selection
 */

test.describe('hardware list & filters', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
  });

  test('list loads with hardware columns', async ({ page }) => {
    const headers = await page.locator('div.hidden.md\\:block table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['نام دستگاه', 'صاحب', 'واحد', 'نوع', 'OS', 'IP', 'CPU', 'RAM', 'HDD', 'وضعیت']) {
      expect(headers).toContain(col);
    }
  });

  test('shows 449 total devices', async ({ page }) => {
    await expect(page.locator('.mary-table-pagination')).toContainText('449');
  });

  test('laptop quick filter narrows results', async ({ page }) => {
    await page.getByRole('button', { name: 'لپ‌تاپ‌ها', exact: true }).click();
    // Reactive wait: filtered list is a single short page (no "از 449" range text).
    // (Polling .wire-loading alone races — it may not exist yet right after click.)
    await expect(page.locator('.mary-table-pagination')).not.toContainText('از 449', {
      timeout: 10000,
    });
    const pag = await page.locator('.mary-table-pagination').innerText().catch(() => '');
    expect(pag).not.toContain('449');
  });

  test('clear filters restores full list', async ({ page }) => {
    await page.getByRole('button', { name: 'لپ‌تاپ‌ها', exact: true }).click();
    // Wait for Livewire filter to complete
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await page.getByRole('button', { name: 'پاکسازی', exact: true }).click();
    // Wait for Livewire clear filter to complete
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('.mary-table-pagination')).toContainText('449');
  });

  test('advanced filter panel opens with نوع دستگاه field', async ({ page }) => {
    await page.locator('button[wire\\:click*="showFilters"]').click();
    // Wait for Livewire to render the filter panel
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('body')).toContainText('نوع دستگاه');
    await expect(page.locator('body')).toContainText('سیستم عامل');
  });

  test('row checkboxes and bulk toolbar present', async ({ page }) => {
    expect(await page.locator('table input[type="checkbox"]').count()).toBeGreaterThan(0);
    // Bulk buttons disabled while nothing selected.
    const bulkDelete = page.getByRole('button', { name: 'حذف', exact: true });
    const bulkMark = page.getByRole('button', { name: 'علامت', exact: true });
    await expect(bulkDelete).toBeDisabled();
    await expect(bulkMark).toBeDisabled();
  });
});
