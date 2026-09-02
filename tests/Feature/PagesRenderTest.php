<?php

use App\Models\Boundary;
use App\Models\Person;
use App\Models\Region;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(Ticket::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    $this->user = User::factory()->create([
        'n_code' => '1234567890',
        'password' => Hash::make('password'),
    ]);
    $this->user->givePermissionTo('map');
    Session::put('current_unit_id', $this->unit->id);
});

$guestProtectedRoutes = [
    '/search' => 'search.index',
    '/settings' => 'settings.index',
    '/profile' => 'profile.index',
    '/tools' => 'tools.tools',
    '/dashboard' => 'dashboard',
    '/maps/route' => 'maps/route',
    '/maps/route2' => 'maps/route2',
    '/maps/unit' => 'maps/unit',
    '/maps/interactive' => 'maps/interactive',
    '/maps/point' => 'maps/point',
    '/it/networks' => 'it/networks',
    '/it/wireless' => 'it/wireless',
];

foreach ($guestProtectedRoutes as $route => $component) {
    test("guest is redirected from $route", function () use ($route) {
        $this->get($route)->assertRedirect('/login');
    });

    test("authenticated user can load $route", function () use ($route, $component) {
        $this->actingAs($this->user);

        if ($component === 'search.index') {
            $this->get($route)->assertStatus(200);
        } else {
            Livewire::test($component)->assertStatus(200);
        }
    });
}

test('guest is redirected from /maps/county', function () {
    $this->get('/maps/county')->assertRedirect('/login');
});

test('authenticated user can load /maps/county', function () {
    $boundary = Boundary::create([
        'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326)"),
    ]);
    $region = Region::create([
        'name' => 'شهرستان تست',
        'type' => 'county',
        'boundary_id' => $boundary->id,
    ]);
    $this->unit->update(['region_id' => $region->id]);

    $this->actingAs($this->user);
    Livewire::test('maps.county')->assertStatus(200);
});

test('select-context redirects to dashboard when single unit is assigned', function () {
    $this->actingAs($this->user);
    $this->get('/select-context')->assertRedirect('/dashboard');
});

test('select-context loads when multiple units exist', function () {
    $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
    $secondUnit = Unit::create(['name' => 'واحد دوم']);
    $this->user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

    $this->actingAs($this->user);
    Livewire::test('select-context')->assertStatus(200);
});

$permissionRoutes = [
    '/activity-log' => 'activity-log.index',
    '/permissions' => 'permissions/index',
    '/roles' => 'roles/index',
];

foreach ($permissionRoutes as $route => $component) {
    test("guest is redirected from $route", function () use ($route) {
        $this->get($route)->assertRedirect('/login');
    });

    test("$route returns 403 for user without permission", function () use ($route) {
        $this->actingAs($this->user);
        $this->get($route)->assertStatus(403);
    });
}

test('/activity-log loads for authorized user', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage_users');
    Livewire::test('activity-log.index')->assertStatus(200);
});

test('/permissions loads for authorized user', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage_roles');
    Livewire::test('permissions/index')->assertStatus(200);
});

test('/roles loads for authorized user', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage_roles');
    Livewire::test('roles/index')->assertStatus(200);
});
