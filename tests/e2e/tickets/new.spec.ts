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
 * NOTE: the two create tests below intentionally mutate data (new ticket +
 * auto-created Todo) — allowed per plan 005. Subjects use a timestamp so runs
 * don't collide; created tickets remain in the sent box (no cheap UI delete).
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
    // Wait for Livewire validation response
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    // unit_id, subject, content are all required — the errors box appears.
    const body = await page.locator('body').innerText();
    // subject min:5, content min:10, unit_id required → at least one validation surfaced
    expect(body).toMatch(/واحد|موضوع|شرح|الزامی|حداقل/);
  });

  test('invalid subject (too short) shows validation', async ({ page }) => {
    await page.locator('input[wire\\:model="subject"]').fill('abc');
    await page.locator('textarea[wire\\:model="content"]').fill('this is long enough content for the description field');
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    // Wait for Livewire validation response
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('body')).toContainText(/حداقل|موضوع/);
  });

  test('cancel resets the form without creating a ticket', async ({ page }) => {
    await page.locator('input[wire\\:model="subject"]').fill('موضوع آزمایشی تست');
    await page.getByRole('button', { name: 'لغو' }).click();
    // Wait for Livewire to reset the form
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 5000 });
    await expect(page.locator('input[wire\\:model="subject"]')).toHaveValue('');
  });

  test('create valid ticket appears in inbox', async ({ page }) => {
    const ts = Date.now();
    const subject = `تست خودکار E2E ${ts}`;
    await page.locator('input[placeholder^="جستجوی واحد"]').first().fill('زنجان');
    // Wait for unit search results to appear (reactive instead of arbitrary timeout)
    await page.waitForSelector('[wire\\:click*="selectUnit"]', { state: 'visible', timeout: 10000 });
    await page.locator('[wire\\:click*="selectUnit"]').first().click();
    // Wait for Livewire to process the unit selection
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 5000 });
    await page.locator('select').first().selectOption('urgent');
    await page.locator('input[wire\\:model="subject"]').fill(subject);
    await page
      .locator('textarea[wire\\:model="content"]')
      .fill('این یک متن تستی برای بررسی ثبت تیکت به صورت خودکار است که بیش از بیست کاراکتر دارد');
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await expect(page.locator('.toast').first()).toContainText('تیکت با موفقیت ثبت شد', {
      timeout: 10000,
    });
    // created tickets land in the sent box, not the received inbox
    await page.goto('/tickets/inbox?viewMode=sent');
    await page.waitForLoadState('networkidle');
    // Wait for Livewire to load inbox data
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    const search = page.locator('input[placeholder*="جستجوی کد یا موضوع"]');
    await search.clear();
    await search.pressSequentially(String(ts), { delay: 80 });
    // Wait for search results table rows to appear (reactive instead of arbitrary timeout)
    await page.waitForSelector('table tbody tr', { timeout: 10000 });
    const rows = page.locator('table tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 10000 });
    await expect(rows.first()).toContainText('تست خودکار E2E');
  });

  test('create with attachment', async ({ page }) => {
    const ts = Date.now();
    const subject = `تست فایل E2E ${ts}`;
    await page.locator('input[placeholder^="جستجوی واحد"]').first().fill('زنجان');
    // Wait for unit search results to appear
    await page.waitForSelector('[wire\\:click*="selectUnit"]', { state: 'visible', timeout: 10000 });
    await page.locator('[wire\\:click*="selectUnit"]').first().click();
    // Wait for Livewire to process the unit selection
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 5000 });
    await page.locator('select').first().selectOption('urgent');
    await page.locator('input[wire\\:model="subject"]').fill(subject);
    await page
      .locator('textarea[wire\\:model="content"]')
      .fill('متن تستی برای بررسی ثبت تیکت همراه با فایل پیوست که به اندازه کافی طولانی است');
    await page.locator('input[type="file"]').setInputFiles({
      name: 'e2e-attach.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF'),
    });
    // Wait for file input to be processed by the browser
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 5000 });
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await expect(page.locator('.toast').first()).toContainText('تیکت با موفقیت ثبت شد', {
      timeout: 15000,
    });
  });
});
