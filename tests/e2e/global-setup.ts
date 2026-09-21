/**
 * Global setup — runs once before the entire Playwright suite.
 *
 * 1. Backs up .env → .env.dev.bak, copies .env.e2e → .env
 * 2. Runs migrate:fresh --seed on the E2E database
 * 3. Creates a dedicated password-mutation user
 * 4. Writes .run-state.json for test fixtures
 *
 * NOTE: The artisan serve process must be started SEPARATELY before running
 * playwright. This setup only prepares the database and config.
 */
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';

const PROJECT_ROOT = process.cwd();
const ENV_PATH = path.join(PROJECT_ROOT, '.env');
const ENV_DEV_BAK = path.join(PROJECT_ROOT, '.env.dev.bak');
const ENV_E2E = path.join(PROJECT_ROOT, '.env.e2e');
const RUN_STATE_PATH = path.join(PROJECT_ROOT, 'tests', 'e2e', '.run-state.json');
const CREATE_PWD_USER = path.join(PROJECT_ROOT, 'tests', 'e2e', 'create-pwd-user.php');

export default async function globalSetup() {
  if (!process.env.TEST_PASSWORD) {
    throw new Error('TEST_PASSWORD env var is required. Set it in .env.e2e');
  }

  const runId = Date.now().toString(36);
  const pwdNCode = '9' + Date.now().toString().slice(-9);
  const unitName = `E2E-${runId}`;

  // 1. Swap .env
  if (fs.existsSync(ENV_PATH) && !fs.existsSync(ENV_DEV_BAK)) {
    fs.copyFileSync(ENV_PATH, ENV_DEV_BAK);
  }
  fs.copyFileSync(ENV_E2E, ENV_PATH);
  console.log('[global-setup] Swapped .env → .env.dev.bak, .env.e2e → .env');

  // 2. Fresh database + seed
  console.log(`[global-setup] runId=${runId} — migrating and seeding...`);
  execSync('php artisan config:clear && php artisan route:clear', {
    cwd: PROJECT_ROOT,
    stdio: 'inherit',
  });
  execSync('php artisan migrate:fresh --seed --force', {
    cwd: PROJECT_ROOT,
    stdio: 'inherit',
  });

  // 3. Create dedicated password-mutation user
  console.log(`[global-setup] Creating password-mutation user ${pwdNCode}...`);
  execSync(`php ${CREATE_PWD_USER} ${pwdNCode} ${process.env.TEST_PASSWORD} "${unitName}"`, {
    cwd: PROJECT_ROOT,
    stdio: 'inherit',
  });

  // 4. Write run state
  const state = { runId, pwdNCode };
  fs.writeFileSync(RUN_STATE_PATH, JSON.stringify(state, null, 2));
  console.log(`[global-setup] Run state: ${JSON.stringify(state)}`);
}
