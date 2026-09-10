import { test, expect, login } from '../shared/fixtures';

const NEW_PASSWORD = 'newpass12345';

async function gotoChangePassword(page) {
  await page.goto('/users/changepassword');
  await expect(page.locator('input[wire\\:model="currentPassword"]')).toBeVisible();
}

test.describe('Authentication — change password', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('valid current + matching new password shows success toast', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill('12345678');
    await page.locator('input[wire\\:model="newPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    await expect(page.locator('.toast').first()).toContainText('رمز با موفقیت تغییر یافت', { timeout: 10000 });

    // Change it back so the shared admin account's password is not left altered.
    await page.locator('input[wire\\:model="currentPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPassword"]').fill('12345678');
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill('12345678');
    await page.getByRole('button', { name: 'تغییر رمز' }).click();
    await expect(page.locator('.toast').first()).toContainText('رمز با موفقیت تغییر یافت', { timeout: 10000 });
  });

  test('wrong current password shows error', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill('WRONG123');
    await page.locator('input[wire\\:model="newPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    // Error appears both inline under the field and in the x-errors box.
    await expect(page.locator('text=رمز فعلی اشتباه است.').first()).toBeVisible();
  });

  test('mismatched confirmation shows error', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill('12345678');
    await page.locator('input[wire\\:model="newPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill('differentthing99x');
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    await expect(page.locator('text=مطابقت داشته باشند').first()).toBeVisible();
  });

  test('weak new password shows validation error', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill('12345678');
    await page.locator('input[wire\\:model="newPassword"]').fill('short');
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill('short');
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    await expect(page.locator('text=حداقل 8 کاراکتر').first()).toBeVisible();
  });
});