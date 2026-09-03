<?php

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

covers(Hardware::class);

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
});

test('guest is redirected from search page', function () {
    $this->get('/search')->assertRedirect('/login');
});

test('authenticated user can load search page', function () {
    $this->actingAs($this->user);
    $this->get('/search')->assertStatus(200);
});

test('search page starts with empty state', function () {
    $this->actingAs($this->user);

    Livewire::test('search.index')
        ->assertSet('query', '')
        ->assertSet('hasSearched', false)
        ->assertSet('results.tickets', [])
        ->assertSet('results.todos', [])
        ->assertSet('results.users', [])
        ->assertSet('results.units', []);
});

test('search page filters tickets by query', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('view_all_tickets');

    Ticket::create([
        'user_id' => $this->user->id,
        'unit_id' => $this->unit->id,
        'ticket_code' => 'TKT-'.uniqid(),
        'subject' => 'تست جستجو',
        'content' => 'محتوا',
        'status' => 'created',
        'priority' => 'urgent',
    ]);

    Livewire::test('search.index')
        ->set('query', 'جستجو')
        ->call('search')
        ->assertSee('تست جستجو');
});
