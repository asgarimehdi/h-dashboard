<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $this->user->givePermissionTo('view_assigned_tickets');
    $this->user->givePermissionTo('create_ticket');
    $this->user->givePermissionTo('view_all_tickets');

    Session::put('current_unit_id', $this->unit->id);
});

test('tickets inbox renders tickets assigned to user', function () {
    $otherUser = User::factory()->create();

    $ticket = Ticket::create([
        'ticket_code' => 'TKT-0001',
        'user_id' => $otherUser->id,
        'unit_id' => $this->unit->id,
        'subject' => 'تیکت اختصاص یافته',
        'content' => 'توضیحات',
        'status' => 'forwarded',
        'priority' => 'normal',
        'current_assignee_id' => $this->user->id,
    ]);

    $otherTicket = Ticket::create([
        'ticket_code' => 'TKT-0002',
        'user_id' => $otherUser->id,
        'unit_id' => $this->unit->id,
        'subject' => 'تیکت دیگر',
        'content' => 'توضیحات',
        'status' => 'forwarded',
        'priority' => 'normal',
        'current_assignee_id' => $otherUser->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('tickets.inbox')
        ->set('viewMode', 'sent')
        ->assertOk()
        ->assertSee('تیکت اختصاص یافته')
        ->assertDontSee('تیکت دیگر');
});

test('creating ticket persists data', function () {
    $targetUnit = Unit::create([
        'name' => 'واحد مقصد',
        'can_receive_tickets' => true,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test('tickets.create')
        ->set('unit_id', $targetUnit->id)
        ->set('subject', 'تیکت جدید تست')
        ->set('content', 'توضیحات تست تست تست')
        ->set('priority', 'high')
        ->call('saveTicket');

    $this->assertDatabaseHas('tickets', [
        'subject' => 'تیکت جدید تست',
        'unit_id' => $targetUnit->id,
    ]);
});

test('monitoring page renders tickets and displays Persian status', function () {
    Ticket::create([
        'ticket_code' => 'TKT-0003',
        'user_id' => $this->user->id,
        'unit_id' => $this->unit->id,
        'subject' => 'تیکت مانیتورینگ',
        'content' => 'توضیحات',
        'status' => 'created',
        'priority' => 'low',
    ]);

    Livewire::actingAs($this->user)
        ->test('tickets.monitoring')
        ->assertOk()
        ->assertSee('تیکت مانیتورینگ')
        ->assertSee('جدید'); // statusName for 'created'
});

test('tickets pages are protected by RBAC', function () {
    $noPermUser = User::factory()->create();

    // Inbox requires 'view_assigned_tickets'
    $this->actingAs($noPermUser)
        ->get('/tickets/inbox')
        ->assertStatus(403);

    // Create requires 'create_ticket'
    $this->actingAs($noPermUser)
        ->get('/tickets/new')
        ->assertStatus(403);

    // Monitoring requires 'view_all_tickets'
    $this->actingAs($noPermUser)
        ->get('/monitoring')
        ->assertStatus(403);
});

test('ticket model helpers return expected values', function () {
    $ticket = new Ticket(['status' => 'created']);
    expect($ticket->canBeCompleted())->toBeFalse();

    $ticketNew = new Ticket(['status' => 'accepted']);
    expect($ticketNew->canBeCompleted())->toBeTrue();
});
