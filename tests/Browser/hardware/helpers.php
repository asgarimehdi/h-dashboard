<?php

/*
|--------------------------------------------------------------------------
| Hardware Browser Tests — Helpers
|--------------------------------------------------------------------------
|
| Shared setup / teardown helpers for hardware browser tests.
| Creates a user with manage_hardware permission, a person, a unit,
| and sample hardware records. Logs in through the browser.
|
*/

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * Seed permissions and create lookup rows required by Person model.
 */
function seedHardwarePrereqs(): void
{
    if (DB::table('permissions')->count() === 0) {
        app()->make(Kernel::class)->call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
    }

    // Ensure foreign-key parent rows exist (idempotent).
    DB::table('tahsils')->insertOrIgnore(['id' => 1, 'name' => 'تست']);
    DB::table('estekhdams')->insertOrIgnore(['id' => 1, 'name' => 'تست']);
    DB::table('semats')->insertOrIgnore(['id' => 1, 'name' => 'تست']);
    DB::table('radifs')->insertOrIgnore(['id' => 1, 'name' => 'تست']);

    // Resync sequences after explicit inserts.
    DB::statement("SELECT setval('tahsils_id_seq', COALESCE((SELECT MAX(id) FROM tahsils), 1))");
    DB::statement("SELECT setval('estekhdams_id_seq', COALESCE((SELECT MAX(id) FROM estekhdams), 1))");
    DB::statement("SELECT setval('semats_id_seq', COALESCE((SELECT MAX(id) FROM semats), 1))");
    DB::statement("SELECT setval('radifs_id_seq', COALESCE((SELECT MAX(id) FROM radifs), 1))");
}

/**
 * Create a user with manage_hardware permission, linked person, and unit.
 * Returns [user, unit, n_code].
 */
function createHardwareAdmin(): array
{
    seedHardwarePrereqs();

    $unit = Unit::create(['name' => 'واحد تست سخت‌افزار']);

    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'علی',
        'l_name' => 'رضايي',
        't_id' => 1,
        'e_id' => 1,
        's_id' => 1,
        'r_id' => 1,
        'u_id' => $unit->id,
    ]);

    $user = User::create([
        'n_code' => $nCode,
        'password' => Hash::make('password'),
    ]);
    $user->givePermissionTo('manage_hardware');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

    DB::table('user_units')->where('user_id', $user->id)->update([
        'role' => 'staff',
        'is_primary' => true,
    ]);

    return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode];
}

/**
 * Create a hardware record belonging to the given user's person.
 */
function createSampleHardware(Unit $unit, string $nCode, array $overrides = []): Hardware
{
    return Hardware::create(array_merge([
        'n_code' => $nCode,
        'pc_name' => 'PC-Browser-'.fake()->unique()->bothify('####'),
        'type' => 'PC',
        'os' => 'Windows 11',
        'cpu' => 'Intel i5-12400',
        'ram' => '16384',
        'hdd' => 'SSD 512GB',
        'ip_local' => '192.168.1.100',
        'net_type' => 'wired',
    ], $overrides));
}

/**
 * Log in through the browser using the given national code.
 */
function loginThroughBrowser(string $nCode, string $password = 'password'): AwaitableWebpage
{
    bootBrowser();

    return visit('/login')
        ->waitForText('ورود به حساب کاربری')
        ->type('#n_code', $nCode)
        ->type('#password', $password)
        ->click('ورود به سیستم')
        ->waitForText('داشبورد مدیریت اطلاعات سلامت');
}
