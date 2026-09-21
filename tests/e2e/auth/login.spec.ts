import { test, expect, TEST_USER } from '../shared/fixtures';

const VALID_N_CODE = TEST_USER.nCode;
const VALID_PASSWORD = TEST_USER.password;

test.describe('Authentication — login', () => {
  test('login page loads correctly', async ({ page }) => {
    await page.goto('/login');
    await expect(page).toHaveTitle(/h-dashboard/);
    await expect(page.locator('#n_code')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('login with valid credentials redirects to dashboard', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#n_code', VALID_N_CODE);
    await page.fill('#password', VALID_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => url.pathname === '/dashboard', { timeout: 15000 });
    await expect(page.locator('text=داشبورد مدیریت اطلاعات سلامت')).toBeVisible();
  });

  test('login with invalid n_code shows error', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#n_code', '0000000000');
    await page.fill('#password', VALID_PASSWORD);
    await page.click('button[type="submit"]');
    await expect(page.locator('text=نام کاربری یا رمز عبور اشتباه است')).toBeVisible();
  });

  test('login with invalid password shows error', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#n_code', VALID_N_CODE);
    await page.fill('#password', 'wrongpassword123');
    await page.click('button[type="submit"]');
    await expect(page.locator('text=نام کاربری یا رمز عبور اشتباه است')).toBeVisible();
  });

  test('empty n_code and password are blocked by required fields', async ({ page }) => {
    await page.goto('/login');
    await page.click('button[type="submit"]');
    // Both inputs are HTML `required`; submitting empty triggers native validation,
    // so the user stays on /login and no server round-trip happens.
    await expect(page).toHaveURL((url) => url.pathname === '/login');
    // Native validation makes the field invalid (validationMessage is non-empty).
    await expect(page.locator('#n_code')).toHaveJSProperty('validity.valueMissing', true);
  });
});