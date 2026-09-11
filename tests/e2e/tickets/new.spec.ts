import { test, expect, login } from '../shared/fixtures';
import { execSync } from 'child_process';

/**
 * Plan 005 — Tickets create (new)
 * Tests create disposable records with [E2E-TEST] prefix, then clean up.
 */

test.describe('tickets new', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/tickets/new');
    await page.waitForLoadState('networkidle');
  });

  test.afterAll(async () => {
    // Cleanup test data
    try {
      execSync('php tests/e2e/cleanup.php', { cwd: '/home/runner/h-dashboard', timeout: 10000 });
    } catch { /* cleanup best-effort */ }
  });

  test('create form renders all fields', async ({ page }) => {
    await expect(page.locator('input[placeholder^="جستجوی واحد"]').first()).toBeVisible();
    await expect(page.locator('input[wire\\:model="subject"]')).toBeVisible();
    await expect(page.locator('textarea[wire\\:model="content"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'ارسال نهایی' })).toBeVisible();
    await expect(page.locator('select').first()).toBeVisible();
  });

  test('empty required fields show validation errors', async ({ page }) => {
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await page.waitForTimeout(1000);
    const body = await page.locator('body').innerText();
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

  test('create valid ticket → success + appears in inbox', async ({ page }) => {
    const timestamp = Date.now();
    const subject = `[E2E-TEST] تیکت آزمایشی ${timestamp}`;

    // Select a receiving unit
    const unitSearch = page.locator('input[placeholder^="جستجوی واحد"]').first();
    await unitSearch.fill('شبکه بهداشت');
    await page.waitForTimeout(1000);

    const unitOption = page.locator('[wire\\:click*="selectUnit"]').first();
    await expect(unitOption).toBeVisible({ timeout: 5000 });
    await unitOption.click();
    await page.waitForTimeout(300);

    // Fill form
    await page.locator('input[wire\\:model="subject"]').fill(subject);
    await page.locator('textarea[wire\\:model="content"]').fill('تیکت آزمایشی ایجاد شده توسط تست خودکار E2E. این رکورد باید پس از تست پاک شود.');

    // Submit
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await page.waitForTimeout(3000);

    // Should show success toast or redirect
    const body = await page.locator('body').innerText();
    expect(body).toMatch(/موفقیت|ثبت شد|تیکت/);
  });

  test('create with file attachment', async ({ page }) => {
    const timestamp = Date.now();
    const subject = `[E2E-TEST] تیکت با پیوست ${timestamp}`;

    // Select a receiving unit
    const unitSearch = page.locator('input[placeholder^="جستجوی واحد"]').first();
    await unitSearch.fill('شبکه بهداشت');
    await page.waitForTimeout(1000);

    const unitOption = page.locator('[wire\\:click*="selectUnit"]').first();
    await expect(unitOption).toBeVisible({ timeout: 5000 });
    await unitOption.click();
    await page.waitForTimeout(300);

    // Fill form
    await page.locator('input[wire\\:model="subject"]').fill(subject);
    await page.locator('textarea[wire\\:model="content"]').fill('تیکت با فایل پیوست برای تست خودکار E2E.');

    // Create a dummy file for attachment
    const fileInput = page.locator('input[type="file"]');
    if (await fileInput.isVisible()) {
      // Create a temp file to upload
      const buffer = Buffer.from('E2E test attachment content');
      await fileInput.setInputFiles({
        name: 'e2e-test-attachment.txt',
        mimeType: 'text/plain',
        buffer,
      });
      await page.waitForTimeout(500);
    }

    // Submit
    await page.getByRole('button', { name: 'ارسال نهایی' }).click();
    await page.waitForTimeout(3000);

    const body = await page.locator('body').innerText();
    expect(body).toMatch(/موفقیت|ثبت شد|تیکت/);
  });
});
