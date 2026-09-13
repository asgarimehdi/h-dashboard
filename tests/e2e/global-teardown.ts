/**
 * Playwright global teardown — runs once after the entire E2E suite.
 *
 * Responsibilities:
 * 1. Remove .run-state.json (contains runId + pwdNCode from this run).
 *
 * Data cleanup: NOT done here. Each run's fresh-seed (in global-setup) obliterates
 * the previous run's data. Row-level deletion is unnecessary and fragile.
 */
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const RUN_STATE_PATH = path.join(__dirname, '.run-state.json');

export default async function globalTeardown() {
  if (fs.existsSync(RUN_STATE_PATH)) {
    fs.unlinkSync(RUN_STATE_PATH);
    console.log(`[global-teardown] Removed ${RUN_STATE_PATH}`);
  } else {
    console.log(`[global-teardown] No run-state file to clean up.`);
  }
}
