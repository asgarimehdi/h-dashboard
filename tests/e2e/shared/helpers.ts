/**
 * E2E test helpers — programmatic data creation for data-independent tests.
 *
 * Uses the Laravel API (Sanctum) to create records with unique per-run prefixes,
 * so tests never depend on specific seed data.
 */
import { execSync } from 'child_process';
import * as path from 'path';

const CWD = path.resolve(__dirname, '../..');

/**
 * Create a Person + User + Unit via artisan tinker, returning the n_code.
 * The person name is prefixed with the runId for uniqueness.
 */
export function createE2EUser(runPrefix: string, suffix = 'user'): string {
  const nCode = `9${Date.now().toString().slice(-9)}`;
  const unitName = `${runPrefix}-${suffix}-unit`;
  const fName = runPrefix;
  const lName = suffix;
  const password = process.env.TEST_PASSWORD || '12345678';

  const phpScript = `
use App\\Models\\Person;
use App\\Models\\Unit;
use App\\Models\\User;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;

\\$nCode = '${nCode}';
\\$unit = Unit::create(['name' => '${unitName}']);
Person::create([
    'n_code' => \\$nCode,
    'f_name' => '${fName}',
    'l_name' => '${lName}',
    'u_id' => \\$unit->id,
    's_id' => 1,
    't_id' => 1,
    'e_id' => 1,
    'r_id' => 1,
]);
\\$user = User::create(['n_code' => \\$nCode, 'password' => Hash::make('${password}')]);
\\$user->units()->attach(\\$unit->id, ['role' => 'responsible', 'is_primary' => true]);
echo \\$nCode;
`;

  const result = execSync(
    `php artisan tinker --execute '${phpScript.replace(/'/g, "'\\''")}' --env=e2e`,
    { cwd: CWD, encoding: 'utf-8', timeout: 30_000 },
  ).trim();

  return result;
}

/**
 * Create a Unit via artisan tinker, returning the unit id.
 */
export function createE2EUnit(runPrefix: string, suffix = 'unit'): string {
  const unitName = `${runPrefix}-${suffix}`;

  const phpScript = `
use App\\Models\\Unit;
\\$u = Unit::create(['name' => '${unitName}']);
echo \\$u->id;
`;

  const result = execSync(
    `php artisan tinker --execute '${phpScript.replace(/'/g, "'\\''")}' --env=e2e`,
    { cwd: CWD, encoding: 'utf-8', timeout: 30_000 },
  ).trim();

  return result;
}
