<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\Unit;
use App\Models\User;
use App\Policies\TicketCommentPolicy;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

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

test('comment author can update own comment within allowed window', function () {
    $author = User::factory()->create();
    $ticket = Ticket::create([
        'user_id' => $author->id,
        'unit_id' => $this->unit->id,
        'ticket_code' => 'TKT-'.uniqid(),
        'subject' => 'Test',
        'content' => 'Test',
        'status' => 'created',
        'priority' => 'medium',
    ]);
    $comment = TicketComment::create([
        'ticket_id' => $ticket->id,
        'user_id' => $author->id,
        'body' => 'Test comment',
    ]);

    $policy = new TicketCommentPolicy();
    expect($policy->update($author, $comment))->toBeTrue();
});

test('non-author cannot update comment', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $ticket = Ticket::create([
        'user_id' => $author->id,
        'unit_id' => $this->unit->id,
        'ticket_code' => 'TKT-'.uniqid(),
        'subject' => 'Test',
        'content' => 'Test',
        'status' => 'created',
        'priority' => 'medium',
    ]);
    $comment = TicketComment::create([
        'ticket_id' => $ticket->id,
        'user_id' => $author->id,
        'body' => 'Test comment',
    ]);

    $policy = new TicketCommentPolicy();
    expect($policy->update($other, $comment))->toBeFalse();
});

test('user with manage_unit_tickets can delete comment', function () {
    $author = User::factory()->create();
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_unit_tickets');
    $ticket = Ticket::create([
        'user_id' => $author->id,
        'unit_id' => $this->unit->id,
        'ticket_code' => 'TKT-'.uniqid(),
        'subject' => 'Test',
        'content' => 'Test',
        'status' => 'created',
        'priority' => 'medium',
    ]);
    $comment = TicketComment::create([
        'ticket_id' => $ticket->id,
        'user_id' => $author->id,
        'body' => 'Test comment',
    ]);

    $policy = new TicketCommentPolicy();
    expect($policy->delete($admin, $comment))->toBeTrue();
});
