<?php

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

test('create_ticket-only account sees no tickets or users in search', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('create_ticket');

    Ticket::create([
        'user_id' => $this->user->id,
        'unit_id' => $this->unit->id,
        'ticket_code' => 'TKT-'.uniqid(),
        'subject' => 'گزارش محرمانه',
        'content' => 'SECRET-BODY-897',
        'status' => 'created',
        'priority' => 'urgent',
    ]);

    $results = Livewire::test('search.index')
        ->set('query', 'محرمانه')
        ->call('search')
        ->get('results');

    expect($results['tickets'])->toBe([])
        ->and($results['users'])->toBe([])
        ->and(json_encode($results))->not->toContain('SECRET-BODY-897');
});

test('manage_users holder still sees users branch', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage_users');

    $colleague = Person::create([
        'n_code' => '9988776655',
        'f_name' => 'QwxKmgmt',
        'l_name' => 'Testi',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);
    User::factory()->create(['n_code' => $colleague->n_code]);

    Livewire::test('search.index')
        ->set('query', 'QwxKmgmt')
        ->call('search')
        ->assertSee('QwxKmgmt');
});

test('search payload exposes only projected keys', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo(['view_all_tickets', 'manage_users']);

    Ticket::create([
        'user_id' => $this->user->id,
        'unit_id' => $this->unit->id,
        'ticket_code' => 'TKT-'.uniqid(),
        'subject' => 'Ticket QwxProj',
        'content' => 'SECRET-CONTENT-897',
        'status' => 'created',
        'priority' => 'urgent',
    ]);

    $colleague = Person::create([
        'n_code' => '1122334455',
        'f_name' => 'QwxColleague',
        'l_name' => 'QwxProj',
        'phone' => '09120000000',
        'birth_date' => '1990-01-01',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);
    User::factory()->create(['n_code' => $colleague->n_code]);

    Todo::create([
        'title' => 'Todo QwxProj',
        'start_at' => now(),
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
    ]);

    $results = Livewire::test('search.index')
        ->set('query', 'QwxProj')
        ->call('search')
        ->get('results');

    $ticket = $results['tickets'][0];
    expect($ticket)->toHaveKeys(['id', 'ticket_code', 'subject', 'status', 'unit'])
        ->and($ticket)->toHaveCount(5)
        ->and($ticket)->not->toHaveKey('content')
        ->and($ticket['unit'])->toHaveKeys(['id', 'name'])
        ->and($ticket['unit'])->toHaveCount(2);

    $foundUser = collect($results['users'])->firstWhere('id', '<>', $this->user->id)
        ?? $results['users'][0];
    expect($foundUser)->toHaveKeys(['id', 'person'])
        ->and($foundUser)->toHaveCount(2)
        ->and($foundUser['person'])->toHaveKeys(['f_name', 'l_name', 'semat'])
        ->and($foundUser['person'])->toHaveCount(3);

    $todo = $results['todos'][0];
    expect($todo)->toHaveKeys(['id', 'title', 'start_at', 'is_completed'])
        ->and($todo)->toHaveCount(4)
        ->and($todo)->not->toHaveKey('user_id');

    $payload = json_encode($results);
    expect($payload)->not->toContain('SECRET-CONTENT-897')
        ->and($payload)->not->toContain('09120000000')
        ->and($payload)->not->toContain('1122334455')
        ->and($payload)->not->toContain('1990-01-01');

    $unitResults = Livewire::test('search.index')
        ->set('query', 'تست')
        ->call('search')
        ->get('results');

    expect($unitResults['units'][0])->toHaveKeys(['id', 'name'])
        ->and($unitResults['units'][0])->toHaveCount(2);
});
