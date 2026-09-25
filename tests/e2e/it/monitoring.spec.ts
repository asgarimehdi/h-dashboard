import { test, expect, login, waitForLivewire, TEST_USER, ROLE_ACCOUNTS } from '../shared/fixtures';

/**
 * Issue #698 — Zabbix monitoring pages + the new device management section.
 *
 * Probed DOM facts:
 * - /it/networks header "داشبورد فناوری اطلاعات" + "ترافیک شبکه" (chart cards are
 *   lazy-island rendered, so we assert the page chrome, not each chart).
 * - /it/wireless header "دستگاه های بی سیم".
 * - /it/zabbix-devices is the CRUD page: "مدیریت دستگاه‌های زبیکس",
 *   a "دستگاه جدید" button, a search input (placeholder "جستجو...") and the
 *   table seeded by ZabbixDeviceSeeder (25 network + 14 wireless rows).
 */

test.describe('IT monitoring pages', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('networks page renders the traffic dashboard', async ({ page }) => {
    await page.goto('/it/networks');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).toContainText('داشبورد فناوری اطلاعات');
    await expect(page.locator('body')).toContainText('ترافیک شبکه');
    await expect(page.locator('body')).not.toContainText('دستگاهی برای نمایش ثبت نشده است');
  });

  test('wireless page renders the signal gauges', async ({ page }) => {
    await page.goto('/it/wireless');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).toContainText('دستگاه های بی سیم');
    await expect(page.locator('body')).not.toContainText('دستگاهی برای نمایش ثبت نشده است');
  });
});

test.describe('Zabbix device management', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/it/zabbix-devices');
    await page.waitForLoadState('networkidle');
  });

  test('management page lists the seeded devices', async ({ page }) => {
    await expect(page.locator('body')).toContainText('مدیریت دستگاه‌های زبیکس');
    await expect(page.locator('body')).toContainText('فیبر اصلی');
    await expect(page.getByRole('button', { name: 'دستگاه جدید' })).toBeVisible();
  });

  test('search narrows the device table', async ({ page }) => {
    const search = page.locator('input[placeholder*="جستجو"]');
    await search.fill('فیبر');
    await waitForLivewire(page);
    await page.waitForTimeout(700); // 500ms debounce + render

    await expect(page.locator('body')).toContainText('فیبر اصلی');
    await expect(page.locator('body')).not.toContainText('بیمارستان الغدیر');
  });

  test('new device form opens with the network fields', async ({ page }) => {
    await page.getByRole('button', { name: 'دستگاه جدید' }).click();
    await waitForLivewire(page);

    await expect(page.locator('input[placeholder*="نام دستگاه"]')).toBeVisible();
    await expect(page.locator('input[placeholder*="مثلاً 73638"]')).toBeVisible();
    await expect(page.getByText('شناسه آیتم خروجی')).toBeVisible();
  });

  test('validation blocks a non numeric item id', async ({ page }) => {
    await page.getByRole('button', { name: 'دستگاه جدید' }).click();
    await waitForLivewire(page);

    await page.locator('input[placeholder*="نام دستگاه"]').fill('دستگاه تست e2e');
    await page.locator('input[placeholder*="مثلاً 73638"]').fill('not-a-number');
    await page.locator('input[placeholder*="مثلاً 73494"]').fill('12345');
    await page.getByRole('button', { name: 'ذخیره' }).click();
    await waitForLivewire(page);

    // the row must NOT be persisted — the invalid item id fails validation
    await expect(page.locator('tbody td', { hasText: 'دستگاه تست e2e' })).toHaveCount(0);
  });
});

test.describe('Zabbix device management — RBAC', () => {
  test('a user without manage_zabbix cannot open the management page', async ({ page }) => {
    await login(page, ROLE_ACCOUNTS.user, TEST_USER.password);

    const resp = await page.goto('/it/zabbix-devices');
    expect(resp!.status()).toBe(403);
  });
});
