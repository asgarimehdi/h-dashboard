<?php

/*
|--------------------------------------------------------------------------|
| Tickets Browser Tests — Helpers
|
| Shared setup / teardown helpers for ticket browser tests.
| Creates a user with ticket permissions, a person, and a unit.
| Logs in through the browser.
|
| Run: pest tests/Browser/tickets --browser
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
function seedTicketPrereqs(): void
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
 * Create a user with ticket permissions, linked person, and unit.
 * Returns [user, unit, n_code].
 */
function createTicketUser(): array
{
    seedTicketPrereqs();

    $unit = Unit::create([
        'name' => 'واحد تست تیکت',
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

    $user->givePermissionTo('create_ticket');
    $user->givePermissionTo('view_assigned_tickets');
    $user->units()->attach($unit->id, ['role' => 'responsible', 'is_primary' => true]);

    return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode];
}

/**
 * Log in through the browser using the given national code.
 */
function loginTicketUser(string $nCode, string $password = 'password'): AwaitableWebpage
{
    bootBrowser();

    return visit('/login')
        ->fill('#n_code', $nCode)
        ->fill('#password', $password)
        ->click('button[type="submit"]')
        ->waitForText('داشبورد مدیریت اطلاعات سلامت');
}
