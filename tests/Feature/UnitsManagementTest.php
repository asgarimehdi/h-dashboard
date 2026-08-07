<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Unit;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    
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

test('unit map renders for accessible unit and 403 for out-of-scope', function () {
    Livewire::actingAs($this->user)
        ->test('units.map', ['id' => $this->unit->id])
        ->assertOk();

    $otherUnit = Unit::create(['name' => 'واحد خارجی']);
    
    Livewire::actingAs($this->user)
        ->test('units.map', ['id' => $otherUnit->id])
        ->assertStatus(403);
});

test('units pages are protected by RBAC', function () {
    $noPermUser = User::factory()->create();
    
    $this->actingAs($noPermUser)
        ->get('/units')
        ->assertStatus(403);
});

test('ValidateUnitContext middleware resolves context correctly', function () {
    // Use a throwaway route to test middleware
    \Illuminate\Support\Facades\Route::middleware('unit_context')->get('/test-context', fn() => 'ok');

    // Case 1: User with no units but person has u_id -> set from person
    $user1 = User::factory()->create();
    $person1 = Person::create([
        'n_code' => $user1->n_code,
        'f_name' => 'تست',
        'l_name' => 'یک',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    
    $this->actingAs($user1);
    $this->get('/test-context');
    expect(Session::get('current_unit_id'))->toBe($this->unit->id);

    // Case 2: User with multiple units and no current_unit_id -> redirect to /select-context
    $user2 = User::factory()->create();
    $u1 = Unit::create(['name' => 'واحد ۱']);
    $u2 = Unit::create(['name' => 'واحد ۲']);
    $user2->units()->attach([$u1->id, $u2->id]);
    
    Session::flush();
    $this->actingAs($user2);
    $this->get('/test-context')
        ->assertRedirect('/select-context');

    // Case 3: User with one unit -> set from unit
    $user3 = User::factory()->create();
    $u3 = Unit::create(['name' => 'واحد ۳']);
    $user3->units()->attach([$u3->id]);
    
    Session::flush();
    $this->actingAs($user3);
    $this->get('/test-context');
    expect(Session::get('current_unit_id'))->toBe($u3->id);
});
