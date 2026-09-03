<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(ZabbixService::class);

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
        'n_code' => $this->person->n_code,
    ]);

    $this->user->givePermissionTo('map');
    Session::put('current_unit_id', $this->unit->id);
});

// ── it.networks ──

test('networks page requires authentication', function () {
    $this->get('/it/networks')->assertRedirect('/login');
});

test('networks page requires map permission', function () {
    $noPermUser = User::factory()->create();
    Session::put('current_unit_id', $this->unit->id);

    $this->actingAs($noPermUser)
        ->get('/it/networks')
        ->assertStatus(403);
});

test('networks component mounts successfully', function () {
    Livewire::actingAs($this->user)
        ->test('it.networks')
        ->assertOk();
});

test('networks component has hardcoded network items', function () {
    $component = Livewire::actingAs($this->user)
        ->test('it.networks')
        ->assertOk();

    $items = $component->get('networkItems');
    $this->assertCount(25, $items);
    $this->assertEquals('فیبر اصلی', $items[0]['title']);
    $this->assertEquals('73638', $items[0]['out-item-id']);
});

test('networks component shows page title', function () {
    Livewire::actingAs($this->user)
        ->test('it.networks')
        ->assertSee('ترافیک شبکه');
});

test('networks component help modal toggles', function () {
    Livewire::actingAs($this->user)
        ->test('it.networks')
        ->assertSet('showHelpModal', false)
        ->set('showHelpModal', true)
        ->assertSet('showHelpModal', true);
});

// ── it.wireless ──

test('wireless page requires authentication', function () {
    $this->get('/it/wireless')->assertRedirect('/login');
});

test('wireless page requires map permission', function () {
    $noPermUser = User::factory()->create();
    Session::put('current_unit_id', $this->unit->id);

    $this->actingAs($noPermUser)
        ->get('/it/wireless')
        ->assertStatus(403);
});

test('wireless component mounts successfully', function () {
    Livewire::actingAs($this->user)
        ->test('it.wireless')
        ->assertOk();
});

test('wireless component has hardcoded signal items', function () {
    Livewire::actingAs($this->user)
        ->test('it.wireless')
        ->assertSet('signalItems', function ($items) {
            return count($items) === 14
                && $items[0]['name'] === 'اعلایی'
                && $items[0]['signalId'] === '75297';
        });
});

test('wireless component shows page title', function () {
    Livewire::actingAs($this->user)
        ->test('it.wireless')
        ->assertSee('دستگاه های بی سیم');
});

test('wireless component help modal toggles', function () {
    Livewire::actingAs($this->user)
        ->test('it.wireless')
        ->assertSet('showHelpModal', false)
        ->set('showHelpModal', true)
        ->assertSet('showHelpModal', true);
});
