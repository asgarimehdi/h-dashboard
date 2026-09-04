<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

// ==================== Standalone rendering ====================

test('standalone renders with expected markup', function () {
    Livewire::test('maps.map')
        ->assertOk()
        ->assertSeeHtml('id="map"')
        ->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('h-[80lvh]')
        ->assertSeeHtml('rounded');
});

// ==================== Mount defaults ====================

test('mount sets tile template to osm fallback', function () {
    Livewire::test('maps.map')
        ->assertSet(
            'map_tile_template',
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
        );
});

test('mount sets default setview', function () {
    Livewire::test('maps.map')
        ->assertSet('setview', '[36.558188, 48.716125]');
});

test('mount sets default zoom', function () {
    Livewire::test('maps.map')
        ->assertSet('zoom', '8');
});

// ==================== Config override ====================

test('config override changes tile template', function () {
    Config::set('map.tile_url_template', 'https://tiles.example.com/{z}/{x}/{y}.png');

    Livewire::test('maps.map')
        ->assertSet(
            'map_tile_template',
            'https://tiles.example.com/{z}/{x}/{y}.png'
        );
});

// ==================== Parent embedding ====================

test('parent component that embeds maps map renders map container', function () {
    $user = createUserWithMapPermission();

    Livewire::actingAs($user)
        ->test('maps.route')
        ->assertOk()
        ->assertSeeHtml('id="map"');
});

// ==================== Assets present ====================

test('rendered blade template contains expected script assets', function () {
    // @script and @assets blocks are not rendered in Livewire test HTML,
    // so verify the Blade source directly.
    $blade = file_get_contents(
        resource_path('views/livewire/maps/map.blade.php')
    );

    expect($blade)
        ->toContain('function initMap()')
        ->toContain('invalidateSize')
        ->toContain('addEventListener')
        ->toContain('_leaflet_id')
        ->toContain('<style>');
});

// ==================== SPA re-render safety ====================

test('second render does not throw', function () {
    Livewire::test('maps.map')->assertOk();
    Livewire::test('maps.map')->assertOk();
});

// ==================== Helpers ====================

function createUserWithMapPermission(): User
{
    DB::table('tahsils')->insertOrIgnore(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insertOrIgnore(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insertOrIgnore(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insertOrIgnore(['id' => 1, 'name' => 'Test']);

    $unit = Unit::create(['name' => 'واحد تست']);

    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    $user = User::create([
        'n_code' => $nCode,
        'password' => Hash::make('password'),
    ]);

    $user->givePermissionTo('map');
    session(['current_unit_id' => $unit->id]);

    return $user;
}
