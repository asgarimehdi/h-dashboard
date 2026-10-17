import { defineConfig, devices } from '@playwright/test';

// Node ≥ 20.12 loads a dotenv file natively (CI runs Node 24), so the `dotenv`
// package is not needed. Wrapped in try/catch on purpose: `dotenv.config()`
// failed silently when `.env.e2e` was missing, and a bare `npx playwright test`
// is a documented fallback — `process.loadEnvFile()` would otherwise throw and
// abort the run before a single test is listed.
try {
  process.loadEnvFile('.env.e2e');
} catch {
  // No `.env.e2e` here: `scripts/e2e-test.sh` sources it into the environment
  // before launching Playwright, or the app is already running with a swapped
  // `.env`. Existing `process.env` values win either way.
}

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    locale: 'fa-IR',
    timezoneId: 'Asia/Tehran',
    // Livewire needs extra time for requests
    actionTimeout: 10000,
    navigationTimeout: 30000,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  // Global setup handled by scripts/e2e-test.sh (swaps .env, migrates, starts server)
  // No globalSetup/globalTeardown here — the shell script manages the full lifecycle
});
