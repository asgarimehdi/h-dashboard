<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Unit;
use App\Models\Person;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Estekhdam;
use App\Models\Radif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    
    $this->unit = Unit::create(['name' => 'واحد تست']);
    
    $this->semat = Semat::create(['name' => 'تکنسین']);
    $this->tahsil = Tahsil::create(['name' => 'لیسانس']);
    $this->estekhdam = Estekhdam::create(['name' => 'رسمی']);
    $this->radif = Radif::create(['name' => 'ردیف 1']);
    
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => $this->semat->id,
        't_id' => $this->tahsil->id,
        'e_id' => $this->estekhdam->id,
        'r_id' => $this->radif->id,
    ]);
    
    $this->user = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->user->givePermissionTo('kargozini');
    
    Session::put('current_unit_id', $this->unit->id);
});

test('kargozini lookup pages render and allow CRUD', function ($component, $model, $field) {
    Livewire::actingAs($this->user)
        ->test($component)
        ->assertOk();

    // Create new record
    Livewire::actingAs($this->user)
        ->test($component)
        ->set('name', 'تست جدید')
        ->call('store');

    $this->assertDatabaseHas($model, ['name' => 'تست جدید']);
})->with([
    ['kargozini.estekhdam', 'estekhdams', 'name'],
    ['kargozini.tahsil', 'tahsils', 'name'],
    ['kargozini.semat', 'semats', 'name'],
    ['kargozini.radif', 'radifs', 'name'],
]);

test('persons page allows CRUD and respects organizational scope', function () {
    Livewire::actingAs($this->user)
        ->test('kargozini.person')
        ->assertOk()
        ->assertSee('تست کاربر');

    // Create new person
    Livewire::actingAs($this->user)
        ->test('kargozini.person')
        ->set('n_code', '9876543210')
        ->set('f_name', 'علی')
        ->set('l_name', 'رضایی')
        ->set('u_id', $this->unit->id)
        ->set('s_id', $this->semat->id)
        ->set('t_id', $this->tahsil->id)
        ->set('e_id', $this->estekhdam->id)
        ->set('r_id', $this->radif->id)
        ->call('store');

    $this->assertDatabaseHas('persons', ['n_code' => '9876543210']);

    // Org Scope: Create person in other unit
    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    Person::create([
        'n_code' => '1112223334',
        'f_name' => 'خارجی',
        'l_name' => 'کاربر',
        'u_id' => $otherUnit->id,
        's_id' => $this->semat->id,
        't_id' => $this->tahsil->id,
        'e_id' => $this->estekhdam->id,
        'r_id' => $this->radif->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('kargozini.person')
        ->assertDontSee('خارجی کاربر');
});

test('kargozini pages are protected by RBAC', function () {
    $noPermUser = User::factory()->create();
    
    $pages = [
        '/kargozini/estekhdams',
        '/kargozini/tahsils',
        '/kargozini/semats',
        '/kargozini/radifs',
        '/kargozini/persons',
    ];

    foreach ($pages as $url) {
        $this->actingAs($noPermUser)
            ->get($url)
            ->assertStatus(403);
    }
});

test('person search normalizes Persian characters', function () {
    // Person exists as 'احمد' (Persian)
    // Search for 'أحمد' (Arabic/Other variant)
    Livewire::actingAs($this->user)
        ->test('kargozini.person')
        ->set('search', 'أحمد') // Arabic variant
        ->assertSee('تست کاربر');
});
