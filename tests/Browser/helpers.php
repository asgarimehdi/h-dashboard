<?php

/*
|--------------------------------------------------------------------------
| Browser Test Helpers
|--------------------------------------------------------------------------
|
| Shared helper functions for all browser tests. Loaded via Pest.php.
| Do NOT define loginViaBrowser() or similar functions in individual test files.
|
*/

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Playwright\Client;
use Pest\Browser\ServerManager;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Boot the browser runtime (websocket connection + HTTP server) so that
 * browser interactions work inside beforeEach hooks, before Pest's own
 * `__markAsBrowserTest` proxy has run at the start of the test body.
 *
 * Idempotent: Client::connectTo() and http()->bootstrap() both no-op
 * when already connected / already started.
 */
function bootBrowser(): void
{
    Client::instance()->connectTo(
        ServerManager::instance()->playwright()->url(),
    );

    ServerManager::instance()->http()->bootstrap();
}

/**
 * Seed permissions required for browser tests.
 */
function seedBrowserPermissions(): void
{
    $permissions = [
        'manage_users', 'organization', 'kargozini', 'map', 'calendar',
        'view_all_tickets', 'create_ticket', 'view_assigned_tickets',
        'manage_roles', 'op-cache', 'manage_hardware', 'bw',
        'view_hr_dashboard', 'manage_personnel', 'manage_unit_tickets',
        'manage_org_chart',
    ];

    foreach ($permissions as $name) {
        Permission::findOrCreate($name);
    }

    $admin = Role::findOrCreate('admin');
    $admin->syncPermissions($permissions);
}

/**
 * Create a test user with all permissions and associated person/unit.
 */
function createBrowserUser(string $nCode = '1234567890'): User
{
    seedBrowserPermissions();

    $unit = Unit::firstOrCreate(
        ['name' => 'واحد تست مرورگر'],
        ['is_active' => true, 'can_receive_tickets' => true]
    );

    // persons table has NOT NULL FKs: t_id (tahsil), e_id (estekhdam),
    // s_id (semat), r_id (radif). Seed these lookup rows first.
    $tahsil = Tahsil::firstOrCreate(['name' => 'کارشناسی تست']);
    $estekhdam = Estekhdam::firstOrCreate(['name' => 'رسمی تست']);
    $semat = Semat::firstOrCreate(['name' => 'کارشناس تست']);
    $radif = Radif::firstOrCreate(['name' => 'ردیف تست']);

    Person::firstOrCreate(
        ['n_code' => $nCode],
        [
            'f_name' => 'تست',
            'l_name' => 'مرورگر',
            'u_id' => $unit->id,
            't_id' => $tahsil->id,
            'e_id' => $estekhdam->id,
            's_id' => $semat->id,
            'r_id' => $radif->id,
        ]
    );

    $user = User::firstOrCreate(
        ['n_code' => $nCode],
        ['password' => Hash::make('password')]
    );

    $user->syncRoles(['admin']);

    return $user;
}

/**
 * Log in via the browser login form.
 */
function loginViaBrowser(string $nCode = '1234567890', string $password = 'password'): AwaitableWebpage
{
    bootBrowser();

    return visit('/login')
        ->fill('#n_code', $nCode)
        ->fill('#password', $password)
        ->click('button[type="submit"]')
        ->waitForText('داشبورد مدیریت اطلاعات سلامت');
}
