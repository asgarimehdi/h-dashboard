<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Unit;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
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
    $this->user->givePermissionTo('view_assigned_tickets');
    $this->user->givePermissionTo('create_ticket');
    $this->user->givePermissionTo('view_all_tickets');
    
    Session::put('current_unit_id', $this->unit->id);
});

test('tickets inbox renders tickets assigned to user', function () {
    $ticket = Ticket::create([
        'title' => 'تیکت اختصاص یافته',
        'description' => 'توضیحات',
        'status' => 'new',
        'priority' => 'medium',
        'assignee_id' => $this->user->id,
        'unit_id' => $this->unit->id,
    ]);

    $otherUser = User::factory()->create();
    $otherTicket = Ticket::create([
        'title' => 'تیکت دیگر',
        'description' => 'توضیحات',
        'status' => 'new',
        'priority' => 'medium',
        'assignee_id' => $otherUser->id,
        'unit_id' => $this->unit->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('tickets.inbox')
        ->assertOk()
        ->assertSee('تیکت اختصاص یافته')
        ->assertDontSee('تیکت دیگر');
});

test('creating ticket persists data', function () {
    Livewire::actingAs($this->user)
        ->test('tickets.create')
        ->set('title', 'تیکت جدید تست')
        ->set('description', 'توضیحات تست')
        ->set('priority', 'high')
        ->call('save');

    $this->assertDatabaseHas('tickets', [
        'title' => 'تیکت جدید تست',
        'unit_id' => $this->unit->id,
    ]);
});

test('monitoring page renders tickets and displays Persian status', function () {
    Ticket::create([
        'title' => 'تیکت مانیتورینگ',
        'description' => 'توضیحات',
        'status' => 'new',
        'priority' => 'low',
        'unit_id' => $this->unit->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('tickets.monitoring')
        ->assertOk()
        ->assertSee('تیکت مانیتورینگ')
        ->assertSee('جدید'); // statusName for 'new'
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
    $ticket = new Ticket(['status' => 'closed']);
    expect($ticket->canBeCompleted())->toBeFalse();
    
    $ticketNew = new Ticket(['status' => 'new']);
    expect($ticketNew->canBeCompleted())->toBeTrue();
});
