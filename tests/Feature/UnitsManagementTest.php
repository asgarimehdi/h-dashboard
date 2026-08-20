<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

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

    $this->user = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->user->givePermissionTo('organization');

    Session::put('current_unit_id', $this->unit->id);
});

test('units index renders and respects organizational scope', function () {
    // Child unit
    Unit::create(['name' => 'زیرمجموعه تست', 'parent_id' => $this->unit->id]);

    // Out of scope unit
    $otherUnit = Unit::create(['name' => 'واحد خارجی']);

    Livewire::actingAs($this->user)
        ->test('units.index')
        ->assertOk()
        ->assertSee('واحد تست')
        ->assertSee('زیرمجموعه تست')
        ->assertDontSee('واحد خارجی');
});

test('unit map renders for accessible unit and out-of-scope', function () {
    Livewire::actingAs($this->user)
        ->test('units.map', ['id' => $this->unit->id])
        ->assertOk();

    $otherUnit = Unit::create(['name' => 'واحد خارجی']);

    Livewire::actingAs($this->user)
        ->test('units.map', ['id' => $otherUnit->id])
        ->assertOk();
});

test('units pages are protected by RBAC', function () {
    $noPermUser = User::factory()->create();

    $this->actingAs($noPermUser)
        ->get('/units')
        ->assertStatus(403);
});

test('ValidateUnitContext middleware resolves context correctly', function () {
    // Use a throwaway route to test middleware
    Route::middleware('unit_context')->get('/test-context', fn () => 'ok');

    // Case 1: User with no units but person has u_id -> set from person
    Person::create([
        'n_code' => '1111111111',
        'f_name' => 'تست',
        'l_name' => 'یک',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $user1 = User::create(['n_code' => '1111111111', 'password' => bcrypt('password')]);

    $this->actingAs($user1);
    $this->get('/test-context');
    expect(Session::get('current_unit_id'))->toBe($this->unit->id);

    // Case 2: User with multiple units and no current_unit_id -> redirect to /select-context
    Person::create([
        'n_code' => '2222222222',
        'f_name' => 'تست',
        'l_name' => 'دو',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $user2 = User::create(['n_code' => '2222222222', 'password' => bcrypt('password')]);
    $u1 = Unit::create(['name' => 'واحد ۱']);
    $u2 = Unit::create(['name' => 'واحد ۲']);
    $user2->units()->attach([$u1->id, $u2->id]);

    Session::flush();
    $this->actingAs($user2);
    $this->get('/test-context')
        ->assertRedirect('/select-context');

    // Case 3: User with one unit -> set from unit
    Person::create([
        'n_code' => '3333333333',
        'f_name' => 'تست',
        'l_name' => 'سه',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $user3 = User::create(['n_code' => '3333333333', 'password' => bcrypt('password')]);
    $u3 = Unit::create(['name' => 'واحد ۳']);
    $user3->units()->attach([$u3->id]);

    Session::flush();
    $this->actingAs($user3);
    $this->get('/test-context');
    expect(Session::get('current_unit_id'))->toBe($u3->id);
});
