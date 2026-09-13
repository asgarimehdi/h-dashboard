/**
 * Global teardown — runs once after the entire Playwright suite.
 *
 * 1. Kills the artisan serve process
 * 2. Restores .env from .env.dev.bak
 * 3. Removes .run-state.json and .server.pid
 *
 * Data cleanup is handled by the NEXT run's migrate:fresh — NOT here.
 */
import * as fs from 'fs';
import * as path from 'path';
import { execSync } from 'child_process';

const PROJECT_ROOT = process.cwd();
const ENV_PATH = path.join(PROJECT_ROOT, '.env');
const ENV_DEV_BAK = path.join(PROJECT_ROOT, '.env.dev.bak');
const RUN_STATE_PATH = path.join(PROJECT_ROOT, 'tests', 'e2e', '.run-state.json');
const SERVER_PID_PATH = path.join(PROJECT_ROOT, 'tests', 'e2e', '.server.pid');

export default async function globalTeardown() {
  // 1. Kill the server
  if (fs.existsSync(SERVER_PID_PATH)) {
    const pid = parseInt(fs.readFileSync(SERVER_PID_PATH, 'utf-8').trim(), 10);
    try {
      process.kill(pid, 'SIGTERM');
      console.log(`[global-teardown] Killed server PID ${pid}`);
    } catch { /* already dead */ }
    fs.unlinkSync(SERVER_PID_PATH);
  }

  // 2. Restore .env
  if (fs.existsSync(ENV_DEV_BAK)) {
    fs.copyFileSync(ENV_DEV_BAK, ENV_PATH);
    fs.unlinkSync(ENV_DEV_BAK);
    console.log('[global-teardown] Restored .env from .env.dev.bak');
  }

  // 3. Remove run state
  if (fs.existsSync(RUN_STATE_PATH)) {
    fs.unlinkSync(RUN_STATE_PATH);
    console.log('[global-teardown] Removed .run-state.json');
  }
}
