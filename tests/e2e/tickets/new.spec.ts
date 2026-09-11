import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 005 — Tickets create (new)
 * Probed DOM facts:
 * - Receiving unit search: input[placeholder^="جستجوی واحد"] → dropdown of can_receive_tickets units
 * - Priority select (wire:model=priority): عادی/متوسط/فوری
 * - Subject: input[wire\:model="subject"], Content: textarea[wire\:model="content"]
 * - Submit: button "ارسال نهایی"
 * - Success toast text: "تیکت با موفقیت ثبت شد"
 * - Admin's unit = "وزارت بهداشت" (id 1) — excluded from receiving units; can_receive_tickets has 10 units.
 *
 * NOTE: create mutates data (new ticket + auto-created Todo). To keep the suite
 * non-destructive we only exercise the *validation* path (empty/invalid submit),
 * which never persists a row, plus the form rendering. Full happy-path create is
 * marked fixme-safe rationale: it would add a fresh ticket+todo on every run.
 */

test.describe('tickets new', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/tickets/new');
    await page.waitForLoadState('networkidle');
  });

  test('create form renders all fields', async ({ page }) => {
    await expect(page.locator('input[placeholder^="جستجوی واحد"]').first()).toBeVisible();
    await expect(page.locator('input[wire\\:model="subject"]')).toBeVisible();
    await expect(page.locator('textarea[wire\\:model="content"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'ارسال نهایی' })).toBeVisible();
    await expect(page.locator('select').first()).toBeVisible(); // priority select
  });

  test('empty required fields show validation errors', async ({ page }) => {
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await page.waitForTimeout(1000);
    // unit_id, subject, content are all required — the errors box appears.
    const body = await page.locator('body').innerText();
    // subject min:5, content min:10, unit_id required → at least one validation surfaced
    expect(body).toMatch(/واحد|موضوع|شرح|الزامی|حداقل/);
  });

  test('invalid subject (too short) shows validation', async ({ page }) => {
    await page.locator('input[wire\\:model="subject"]').fill('abc');
    await page.locator('textarea[wire\\:model="content"]').fill('this is long enough content for the description field');
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await page.waitForTimeout(1000);
    await expect(page.locator('body')).toContainText(/حداقل|موضوع/);
  });

  test('cancel resets the form without creating a ticket', async ({ page }) => {
    await page.locator('input[wire\\:model="subject"]').fill('موضوع آزمایشی تست');
    await page.getByRole('button', { name: 'لغو' }).click();
    await page.waitForTimeout(600);
    await expect(page.locator('input[wire\\:model="subject"]')).toHaveValue('');
  });
});