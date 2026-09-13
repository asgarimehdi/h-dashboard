import { test, expect, login, TEST_USER, getPwdNCode } from '../shared/fixtures';
import * as crypto from 'crypto';

const NEW_PASSWORD = crypto.randomBytes(12).toString('base64url').slice(0, 16);

/**
 * Login as a specific n_code (not the default TEST_USER).
 * Used to log in as the dedicated password-mutation user.
 */
async function loginAsNC(page: any, nCode: string, password: string) {
  await page.goto('/login');
  await page.fill('#n_code', nCode);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');
  await page.waitForURL((url: any) => !url.pathname.includes('/login'), { timeout: 15000 });
}

async function gotoChangePassword(page: any) {
  await page.goto('/users/changepassword');
  await expect(page.locator('input[wire\\:model="currentPassword"]')).toBeVisible();
}

test.describe('Authentication — change password', () => {
  test.beforeEach(async ({ page }) => {
    // Use the dedicated per-run password-mutation user instead of the shared admin
    const pwdNC = getPwdNCode();
    await loginAsNC(page, pwdNC, TEST_USER.password);
  });

  test('valid current + matching new password shows success toast', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill(TEST_USER.password);
    await page.locator('input[wire\\:model="newPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    await expect(page.locator('.toast').first()).toContainText('رمز با موفقیت تغییر یافت', { timeout: 10000 });

    // Change it back so the dedicated user's password is not left altered
    await page.locator('input[wire\\:model="currentPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPassword"]').fill(TEST_USER.password);
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill(TEST_USER.password);
    await page.getByRole('button', { name: 'تغییر رمز' }).click();
    await expect(page.locator('.toast').first()).toContainText('رمز با موفقیت تغییر یافت', { timeout: 10000 });
  });

  test('wrong current password shows error', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill('WRONG123');
    await page.locator('input[wire\\:model="newPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    await expect(page.locator('text=رمز فعلی اشتباه است.').first()).toBeVisible();
  });

  test('mismatched confirmation shows error', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill(TEST_USER.password);
    await page.locator('input[wire\\:model="newPassword"]').fill(NEW_PASSWORD);
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill('differentthing99x');
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    await expect(page.locator('text=مطابقت داشته باشند').first()).toBeVisible();
  });

  test('weak new password shows validation error', async ({ page }) => {
    await gotoChangePassword(page);

    await page.locator('input[wire\\:model="currentPassword"]').fill(TEST_USER.password);
    await page.locator('input[wire\\:model="newPassword"]').fill('short');
    await page.locator('input[wire\\:model="newPasswordConfirmation"]').fill('short');
    await page.getByRole('button', { name: 'تغییر رمز' }).click();

    await expect(page.locator('text=حداقل 8 کاراکتر').first()).toBeVisible();
  });
});
