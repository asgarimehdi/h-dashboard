<?php

/*
|--------------------------------------------------------------------------|
| Todo Browser Tests — Helpers
|
| Shared setup / teardown helpers for todo browser tests.
| Creates a user with calendar permission, a person, and a unit.
|
| Run: pest tests/Browser/todo --browser
|
*/

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
function seedTodoPrereqs(): void
{
    if (DB::table('permissions')->count() === 0) {
        app()->make(Kernel::class)->call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
    }

    DB::table('tahsils')->insertOrIgnore(['id' => 1, 'name' => 'تست']);
    DB::table('estekhdams')->insertOrIgnore(['id' => 1, 'name' => 'تست']);
    DB::table('semats')->insertOrIgnore(['id' => 1, 'name' => 'تست']);
    DB::table('radifs')->insertOrIgnore(['id' => 1, 'name' => 'تست']);

    DB::statement("SELECT setval('tahsils_id_seq', COALESCE((SELECT MAX(id) FROM tahsils), 1))");
    DB::statement("SELECT setval('estekhdams_id_seq', COALESCE((SELECT MAX(id) FROM estekhdams), 1))");
    DB::statement("SELECT setval('semats_id_seq', COALESCE((SELECT MAX(id) FROM semats), 1))");
    DB::statement("SELECT setval('radifs_id_seq', COALESCE((SELECT MAX(id) FROM radifs), 1))");
}

/**
 * Create a user with calendar + ticket permissions, linked person, and unit.
 * Returns [user, unit, n_code].
 */
function createTodoUser(): array
{
    seedTodoPrereqs();

    $unit = Unit::create([
        'name' => 'واحد تست تسک',
        'can_receive_tickets' => true,
        'is_active' => true,
    ]);

    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'علی',
        'l_name' => 'تست',
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

    $user->givePermissionTo('calendar');
    $user->units()->attach($unit->id, ['role' => 'responsible', 'is_primary' => true]);

    return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode];
}

/**
 * Log in through the browser using the given national code.
 */
function loginTodoUser(string $nCode, string $password = 'password'): AwaitableWebpage
{
    bootBrowser();

    return visit('/login')
        ->fill('#n_code', $nCode)
        ->fill('#password', $password)
        ->click('button[type="submit"]')
        ->waitForText('داشبورد مدیریت اطلاعات سلامت');
}
