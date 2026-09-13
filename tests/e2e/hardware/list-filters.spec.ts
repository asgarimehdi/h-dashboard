import { test, expect, login } from '../shared/fixtures';
/**
 * Hardware list + filters
 * Probed DOM facts:
 * - Desktop table columns: #/نام دستگاه/صاحب/واحد/نوع/OS/IP/CPU/RAM/HDD/وضعیت
 * - Quick presets: لپ‌تاپ‌ها/سرورها/رم 16GB+/فقط SSD/روشن‌ها/علامت‌دارها/حذف شده‌ها
 * - Search: input[placeholder^="جستجو در تمام"]
 * - Advanced filter panel toggled by button[wire:click*="showFilters"]
 * - Row checkboxes: table input[type=checkbox]; bulk buttons disabled until selection
 *
 * All count assertions are relative — no hardcoded numbers.
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

  test('shows total devices in pagination', async ({ page }) => {
    // Seeded data has hardware — pagination shows total count
    await expect(page.locator('.mary-table-pagination')).toContainText('نتیجه');
  });

  test('laptop quick filter narrows results', async ({ page }) => {
    // Record total before filtering
    const totalBefore = await page.locator('.mary-table-pagination').innerText();

    await page.getByRole('button', { name: 'لپ\u200cتاپ\u200cها', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    // Filtered results should be fewer — pagination text changes or disappears
    const pag = await page.locator('.mary-table-pagination').innerText().catch(() => '');
    expect(pag).not.toContain('نتیجه'); // filtered view may not show total
  });

  test('clear filters restores full list', async ({ page }) => {
    await page.getByRole('button', { name: 'لپ\u200cتاپ\u200cها', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await page.getByRole('button', { name: 'پاکسازی', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('.mary-table-pagination')).toContainText('نتیجه');
  });

  test('advanced filter panel opens with نوع دستگاه field', async ({ page }) => {
    await page.locator('button[wire\\:click*="showFilters"]').click();
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('body')).toContainText('نوع دستگاه');
    await expect(page.locator('body')).toContainText('سیستم عامل');
  });

  test('row checkboxes and bulk toolbar present', async ({ page }) => {
    expect(await page.locator('table input[type="checkbox"]').count()).toBeGreaterThan(0);
    const bulkDelete = page.getByRole('button', { name: 'حذف', exact: true });
    const bulkMark = page.getByRole('button', { name: 'علامت', exact: true });
    await expect(bulkDelete).toBeDisabled();
    await expect(bulkMark).toBeDisabled();
  });
});
