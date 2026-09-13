/**
 * Playwright global setup — runs once before the entire E2E suite.
 *
 * Responsibilities:
 * 1. Generate a unique runId for this test run (collision-safe under parallel workers).
 * 2. Recreate the E2E database from scratch (migrate:fresh --seed) so every run
 *    starts with an identical, predictable seed state.
 * 3. Create a dedicated password-mutation user (E2E-<runId>-pwd) with its own
 *    Person + Unit + pivot rows, so password-change.spec.ts never touches the
 *    shared seeded admin account.
 * 4. Persist { runId, pwdNCode } to .run-state.json for specs to consume.
 *
 * Data cleanup: NOT done here. Each run's fresh-seed obliterates the previous
 * run's data. Teardown only removes .run-state.json.
 */
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const RUN_STATE_PATH = path.join(__dirname, '.run-state.json');

export default async function globalSetup() {
  if (!process.env.TEST_PASSWORD) {
    throw new Error(
      'TEST_PASSWORD env var is not set. Copy .env.e2e.example to .env.e2e and fill credentials.',
    );
  }

  // 1. Generate unique runId
  const runId = Date.now().toString(36);

  // 2. Recreate the E2E database
  console.log(`[global-setup] Running migrate:fresh --seed --env=e2e ...`);
  execSync('php artisan migrate:fresh --seed --env=e2e --force', {
    cwd: path.resolve(__dirname, '../..'),
    stdio: 'inherit',
    timeout: 120_000,
  });

  // 3. Create dedicated password-mutation user
  const pwdNCode = `9${runId.slice(-8).padEnd(9, '0')}`.slice(0, 10);
  const pwdFName = `E2E-${runId}`;
  const pwdLName = 'pwd-user';
  const pwdUnitName = `E2E-Unit-${runId}`;
  const pwdPassword = process.env.TEST_PASSWORD || '12345678';

  const phpScript = `
use App\\Models\\Person;
use App\\Models\\Unit;
use App\\Models\\User;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;

\$nCode = '${pwdNCode}';
\$unitName = '${pwdUnitName}';
\$password = '${pwdPassword}';
\$fName = '${pwdFName}';
\$lName = '${pwdLName}';

\$unit = Unit::create(['name' => \$unitName]);
Person::create([
    'n_code' => \$nCode,
    'f_name' => \$fName,
    'l_name' => \$lName,
    'u_id' => \$unit->id,
    's_id' => 1,
    't_id' => 1,
    'e_id' => 1,
    'r_id' => 1,
]);
\$user = User::create(['n_code' => \$nCode, 'password' => Hash::make(\$password)]);
\$user->units()->attach(\$unit->id, ['role' => 'responsible', 'is_primary' => true]);
echo "OK: created pwd user " . \$nCode;
`;

  console.log(`[global-setup] Creating dedicated password-mutation user E2E-${runId}-pwd ...`);
  const tinkerResult = execSync(
    `php artisan tinker --execute '${phpScript.replace(/'/g, "'\\''")}' --env=e2e`,
    {
      cwd: path.resolve(__dirname, '../..'),
      encoding: 'utf-8',
      timeout: 30_000,
    },
  );
  console.log(`[global-setup] ${tinkerResult.trim()}`);

  // 4. Persist run state
  const runState = { runId, pwdNCode };
  fs.writeFileSync(RUN_STATE_PATH, JSON.stringify(runState, null, 2));
  console.log(`[global-setup] Wrote ${RUN_STATE_PATH}: runId=${runId}, pwdNCode=${pwdNCode}`);
}
