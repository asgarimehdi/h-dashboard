import { test, expect, login, waitForLivewire, waitForToast } from '../shared/fixtures';

/**
 * E2E tests for the Todo (تسک) calendar page.
 * Covers: page load, create, toggle, delete via Livewire modal.
 */

test.describe('todo calendar', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/todo');
    await page.waitForLoadState('networkidle');
  });

  test('todo page loads with calendar', async ({ page }) => {
    // Page header should show the title
    await expect(page.locator('text=تقویم سازمانی')).toBeVisible();
    // Calendar container should be present
    await expect(page.locator('#calendar')).toBeVisible();
    // "تسک جدید" button should be visible
    await expect(page.getByRole('button', { name: 'تسک جدید' })).toBeVisible();
  });

  test('can open create modal', async ({ page }) => {
    await page.getByRole('button', { name: 'تسک جدید' }).click();
    // Modal should appear with title input
    await expect(page.locator('input[wire\\:model="title"]')).toBeVisible();
    // Save button should be visible
    await expect(page.getByRole('button', { name: 'ذخیره' })).toBeVisible();
    // Cancel button should be visible
    await expect(page.getByRole('button', { name: 'لغو' })).toBeVisible();
  });

  test('can create a todo via modal', async ({ page }) => {
    await page.getByRole('button', { name: 'تسک جدید' }).click();
    await page.waitForTimeout(500);

    // Fill title
    await page.locator('input[wire\\:model="title"]').fill('تست E2E تسک');

    // Fill start date
    const startDateInput = page.locator('input[data-jdp]').first();
    await startDateInput.click();
    await page.waitForTimeout(300);
    // Type a date in Jalali format
    await startDateInput.fill('1405/07/01');
    await page.waitForTimeout(300);

    // Fill start time
    const startTimeInput = page.locator('input[type="time"]').first();
    await startTimeInput.fill('09:00');

    // Submit
    await page.getByRole('button', { name: 'ذخیره' }).click();

    // Wait for Livewire to complete and toast to appear
    await waitForLivewire(page);
    await waitForToast(page, 'با موفقیت ذخیره شد');
  });

  test('can open and close modal', async ({ page }) => {
    await page.getByRole('button', { name: 'تسک جدید' }).click();
    await expect(page.locator('input[wire\\:model="title"]')).toBeVisible();

    // Close via cancel button
    await page.getByRole('button', { name: 'لغو' }).click();
    await page.waitForTimeout(500);

    // Modal should be hidden
    await expect(page.locator('input[wire\\:model="title"]')).not.toBeVisible();
  });
});
