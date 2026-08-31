<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(\App\Models\Unit::class);

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

test('guest is redirected from maps pages', function () {
    foreach (['/maps/route', '/maps/route2', '/maps/county', '/maps/unit', '/maps/interactive', '/maps/point'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
});

test('authenticated user with map permission can load maps pages', function () {
    $this->actingAs($this->user);

    foreach (['maps/route', 'maps/route2', 'maps/unit', 'maps/interactive', 'maps/point'] as $component) {
        Livewire::test($component)->assertStatus(200);
    }
});

test('maps county page renders map container', function () {
    $this->actingAs($this->user);
    Livewire::test('maps.county')->assertStatus(200);
});
