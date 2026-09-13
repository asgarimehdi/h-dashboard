/**
 * E2E test helpers — programmatic data creation for data-independent tests.
 *
 * Creates records via temporary PHP scripts executed through artisan tinker,
 * so tests never depend on specific seed data.
 */
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';
import * as os from 'os';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const CWD = path.resolve(__dirname, '..', '..', '..');

/**
 * Execute a PHP script via artisan tinker, returning stdout trimmed.
 */
function runPhp(script: string): string {
  const tmpFile = path.join(os.tmpdir(), `e2e-helper-${Date.now()}.php`);
  fs.writeFileSync(tmpFile, script);
  try {
    return execSync(
      `php artisan tinker --execute "$(cat ${tmpFile})" --env=e2e`,
      { cwd: CWD, encoding: 'utf-8', timeout: 30_000 },
    ).trim();
  } finally {
    fs.unlinkSync(tmpFile);
  }
}

/**
 * Create a Person + User + Unit via artisan tinker, returning the n_code.
 */
export function createE2EUser(runPrefix: string, suffix = 'user'): string {
  const nCode = `9${Date.now().toString().slice(-9)}`;
  const unitName = `${runPrefix}-${suffix}-unit`;
  const fName = runPrefix;
  const lName = suffix;
  const password = process.env.TEST_PASSWORD || '12345678';

  const script = `
use App\\Models\\Person;
use App\\Models\\Unit;
use App\\Models\\User;
use Illuminate\\Support\\Facades\\Hash;

$nCode = '${nCode}';
$unit = Unit::create(['name' => '${unitName}']);
Person::create([
    'n_code' => $nCode,
    'f_name' => '${fName}',
    'l_name' => '${lName}',
    'u_id' => $unit->id,
    's_id' => 1,
    't_id' => 1,
    'e_id' => 1,
    'r_id' => 1,
]);
$user = User::create(['n_code' => $nCode, 'password' => Hash::make('${password}')]);
$user->units()->attach($unit->id, ['role' => 'responsible', 'is_primary' => true]);
echo $nCode;
`;

  return runPhp(script);
}

/**
 * Create a Unit via artisan tinker, returning the unit id.
 */
export function createE2EUnit(runPrefix: string, suffix = 'unit'): string {
  const unitName = `${runPrefix}-${suffix}`;

  const script = `
use App\\Models\\Unit;
$u = Unit::create(['name' => '${unitName}']);
echo $u->id;
`;

  return runPhp(script);
}
