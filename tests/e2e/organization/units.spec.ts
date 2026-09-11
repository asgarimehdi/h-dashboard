import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 007 — Units list
 * Probed DOM facts:
 * - Headers: # | نام | توضیحات | نوع واحد | منطقه | واحد بالادستی | پذیرش تیکت
 * - 831 rows shown in pagination (832 total units − admin's own unit excluded)
 * - Search: input[placeholder^="جستجو"]
 * - Ticket acceptance toggle: button[title="تغییر وضعیت پذیرش تیکت"] (icon + فعال/غیرفعال)
 */

test.describe('units list', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/units');
    await page.waitForLoadState('networkidle');
  });

  test('list loads with expected columns', async ({ page }) => {
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['نام', 'نوع واحد', 'منطقه', 'واحد بالادستی', 'پذیرش تیکت']) {
      expect(headers).toContain(col);
    }
  });

  test('shows unit rows with pagination', async ({ page }) => {
    expect(await page.locator('table tbody tr').count()).toBeGreaterThan(0);
    // 832 total units, admin's own (id 1) excluded → 831
    await expect(page.locator('.mary-table-pagination')).toContainText('831');
  });

  test('search by name filters units', async ({ page }) => {
    const search = page.locator('input[placeholder^="جستجو"]').first();
    await search.fill('دانشگاه علوم پزشکی زنجان');
    await page.waitForTimeout(1200);
    await expect(page.locator('table tbody')).toContainText('دانشگاه علوم پزشکی زنجان');
  });

  test('pagination navigates across many pages', async ({ page }) => {
    await page.getByRole('button', { name: 'بعدی' }).click();
    await page.waitForTimeout(1000);
    await expect(page.locator('.mary-table-pagination')).toContainText('نمایش 21 تا 40');
  });

  test('ticket acceptance toggle buttons render with status', async ({ page }) => {
    const toggles = page.locator('button[title="تغییر وضعیت پذیرش تیکت"]');
    expect(await toggles.count()).toBeGreaterThan(0);
    // Each toggle shows either فعال or غیرفعال (icon + text), reflecting can_receive_tickets.
    const firstToggleText = await toggles.first().innerText();
    expect(firstToggleText).toMatch(/فعال|غیرفعال/);
  });

  test('ticket toggle persists and reverts', async ({ page }) => {
    const toggle = page.locator('button[title="تغییر وضعیت پذیرش تیکت"]').first();
    const original = (await toggle.innerText()).trim();
    expect(['فعال', 'غیرفعال']).toContain(original);
    const flipped = original === 'فعال' ? 'غیرفعال' : 'فعال';

    await toggle.click();
    await page.waitForTimeout(1200);
    expect((await toggle.innerText()).trim()).toBe(flipped);
    await expect(page.locator('.toast').first()).toContainText('پذیرش تیکت');

    // Revert to net-zero, then verify persistence across reload.
    await toggle.click();
    await page.waitForTimeout(1200);
    await page.reload();
    await page.waitForLoadState('networkidle');
    const reloaded = page.locator('button[title="تغییر وضعیت پذیرش تیکت"]').first();
    expect((await reloaded.innerText()).trim()).toBe(original);
  });

  test('create modal renders type/region/parent controls without saving', async ({ page }) => {
    await page.locator('button[wire\\:click="openModalForCreate"]').first().click();
    await expect(page.locator('dialog.modal-open')).toHaveCount(1, { timeout: 10000 });
    const modal = page.locator('dialog.modal-open');
    for (const label of ['نوع واحد', 'استان', 'شهرستان', 'واحد بالادستی', 'پذیرش تیکت']) {
      await expect(modal).toContainText(label);
    }
    // Escape does not dismiss this MaryUI modal (probed: dialog stays open),
    // so close via the لغو (resetForm) button — nothing is saved.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    if ((await page.locator('dialog.modal-open').count()) > 0) {
      await modal.locator('button:has-text("لغو")').click();
    }
    await expect(page.locator('dialog.modal-open')).toHaveCount(0, { timeout: 10000 });
  });

  test('map link present', async ({ page }) => {
    const href = await page.locator('a[href*="/units/"]').first().getAttribute('href');
    expect(href).toMatch(/\/units\/\d+\/map/);
  });
});
