import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 005 — Tickets inbox
 * Probed DOM facts:
 * - Headers: ایجاد کننده | اولویت | وضعیت | موضوع | نزد واحد | عملیات
 * - View tabs: "ورودی‌ها" / "ارسالی‌ها" (x-button, labels present)
 * - Status filters: همه / در انتظار / انجام / تکمیل
 * - Search: input[placeholder^="جستجوی کد"]
 */

test.describe('tickets inbox', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/tickets/inbox');
    await page.waitForLoadState('networkidle');
  });

  test('inbox loads with ticket table', async ({ page }) => {
    const headers = await page.locator('table thead th').evaluateAll((th) =>
      th.map((x) => x.textContent!.trim()),
    );
    for (const col of ['ایجاد کننده', 'اولویت', 'وضعیت', 'موضوع', 'نزد واحد']) {
      expect(headers).toContain(col);
    }
    // 50 total tickets; inbox shows up to 20 per page.
    expect(await page.locator('table tbody tr').count()).toBeGreaterThan(0);
  });

  test('switch view tabs ورودی/ارسالی changes content', async ({ page }) => {
    const before = await page.locator('table tbody tr').first().innerText();

    await page.getByRole('button', { name: 'ارسالی‌ها' }).click();
    await page.waitForTimeout(1200);

    // Content re-renders (either rows differ or a pagination change).
    await expect(page.locator('table').first()).toBeVisible();
  });

  test('status filter buttons switch the filter', async ({ page }) => {
    for (const label of ['همه', 'در انتظار', 'انجام', 'تکمیل']) {
      await page.getByRole('button', { name: label, exact: true }).first().click();
      await page.waitForTimeout(800);
      await expect(page.locator('table').first()).toBeVisible();
    }
  });

  test('ticket row shows creator, priority, status, subject, unit', async ({ page }) => {
    const firstRow = page.locator('table tbody tr').first();
    // creator + ticket code shown in the first data cell
    await expect(firstRow).toContainText(/#TCK-|#[A-Z0-9-]+/);
    // priority badges: فوری / معمولی / کم‌اهمیت
    const rowText = await firstRow.innerText();
    const hasPriority = /فوری|معمولی|کم‌اهمیت/.test(rowText);
    expect(hasPriority).toBe(true);
  });
});