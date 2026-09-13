<?php

/**
 * Create a dedicated password-mutation user for E2E tests.
 * Usage: php tests/e2e/create-pwd-user.php <n_code> <password> <unit_name>
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$nCode = $argv[1] ?? null;
$password = $argv[2] ?? null;
$unitName = $argv[3] ?? null;

if (! $nCode || ! $password || ! $unitName) {
    echo 'Usage: php create-pwd-user.php <n_code> <password> <unit_name>'."\n";
    exit(1);
}

DB::beginTransaction();
try {
    DB::table('units')->insert([
        'name' => $unitName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $unitId = DB::getPdo()->lastInsertId();

    DB::table('persons')->insert([
        'n_code' => $nCode,
        'f_name' => 'E2E',
        'l_name' => 'PwdUser',
        'u_id' => $unitId,
        't_id' => 1,
        's_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('users')->insert([
        'n_code' => $nCode,
        'password' => Hash::make($password),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $userId = DB::getPdo()->lastInsertId();

    DB::table('user_units')->insert([
        'user_id' => $userId,
        'unit_id' => $unitId,
        'role' => 'responsible',
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::where('n_code', $nCode)->first();
    $user->assignRole('admin');

    DB::commit();
    echo "OK: created user $nCode (id=$userId) with unit $unitId\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo 'ERROR: '.$e->getMessage()."\n";
    exit(1);
}
