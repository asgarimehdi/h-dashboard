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
use Tests\TestCase;

covers(\App\Models\TicketComment::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    // The seeder only creates permissions; the admin ROLE is created on demand
    // by tests that need it (same convention as TicketApiTest).
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);

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

test('comment policy allows author when accessible unit check passes', function () {
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

test('comment policy denies non-author even with accessible unit', function () {
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

test('comment policy denies user without accessible unit', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $otherUnit = \App\Models\Unit::create(['name' => 'واحد دیگر']);
    $other->units()->attach($otherUnit->id, ['role' => 'staff', 'is_primary' => false]);

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

// ---------------------------------------------------------------------------
// Full coverage of view / delete / restore / forceDelete (#494 audit gap).
// Every actor gets an EXPLICIT unit attachment so org-scope assertions don't
// depend on UserFactory's implicit Person→unit fallback.
// ---------------------------------------------------------------------------

function policyTicket(int $unitId, int $authorId): Ticket
{
    return Ticket::create([
        'user_id' => $authorId,
        'unit_id' => $unitId,
        'ticket_code' => 'TKT-'.uniqid(),
        'subject' => 'Policy test',
        'content' => 'Policy test',
        'status' => 'created',
        'priority' => 'medium',
    ]);
}

function policyComment(Ticket $ticket, int $userId): TicketComment
{
    return TicketComment::create([
        'ticket_id' => $ticket->id,
        'user_id' => $userId,
        'body' => 'Policy test comment',
    ]);
}

test('policy view follows organizational scope', function () {
    $author = User::factory()->create();
    $inScope = User::factory()->create();
    $outScope = User::factory()->create();
    $otherUnit = Unit::create(['name' => 'واحد خارج از محدوده']);
    $inScope->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => false]);
    $outScope->units()->attach($otherUnit->id, ['role' => 'staff', 'is_primary' => false]);

    $comment = policyComment(policyTicket($this->unit->id, $author->id), $author->id);
    $policy = new TicketCommentPolicy();

    expect($policy->view($inScope, $comment))->toBeTrue()
        ->and($policy->view($outScope, $comment))->toBeFalse();
});

test('policy update blocks author outside window and never grants editors', function () {
    $author = User::factory()->create();
    $editor = User::factory()->create();
    $editor->givePermissionTo('manage_unit_tickets');
    $author->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => false]);
    $editor->units()->attach($this->unit->id, ['role' => 'responsible', 'is_primary' => false]);

    $comment = policyComment(policyTicket($this->unit->id, $author->id), $author->id);
    // Age the comment past the 15-minute edit window.
    $comment->forceFill(['created_at' => now()->subMinutes(16)])->save();

    $policy = new TicketCommentPolicy();

    expect($policy->update($author, $comment))->toBeFalse()
        ->and($policy->update($editor, $comment))->toBeFalse();
});

test('policy delete allows author, managers and admins only inside scope', function () {
    $author = User::factory()->create();
    $admin = User::factory()->create();
    $manager = User::factory()->create();
    $plain = User::factory()->create();
    $admin->assignRole('admin');
    $manager->givePermissionTo('manage_unit_tickets');
    foreach ([$author, $admin, $manager, $plain] as $u) {
        $u->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => false]);
    }

    $comment = policyComment(policyTicket($this->unit->id, $author->id), $author->id);
    $comment->forceFill(['created_at' => now()->subHour()])->save(); // beyond edit window
    $policy = new TicketCommentPolicy();

    expect($policy->delete($author, $comment))->toBeTrue()
        ->and($policy->delete($admin, $comment))->toBeTrue()
        ->and($policy->delete($manager, $comment))->toBeTrue()
        ->and($policy->delete($plain, $comment))->toBeFalse();
});

test('policy delete denies privileged users outside organizational scope', function () {
    $author = User::factory()->create();
    $outAdmin = User::factory()->create();
    $outManager = User::factory()->create();
    $otherUnit = Unit::create(['name' => 'واحد دیگر دو']);
    $outAdmin->assignRole('admin');
    $outManager->givePermissionTo('manage_unit_tickets');
    $outAdmin->units()->attach($otherUnit->id, ['role' => 'responsible', 'is_primary' => false]);
    $outManager->units()->attach($otherUnit->id, ['role' => 'responsible', 'is_primary' => false]);

    $comment = policyComment(policyTicket($this->unit->id, $author->id), $author->id);
    $policy = new TicketCommentPolicy();

    expect($policy->delete($outAdmin, $comment))->toBeFalse()
        ->and($policy->delete($outManager, $comment))->toBeFalse();
});

test('policy restore requires manager or admin inside scope', function () {
    $author = User::factory()->create();
    $admin = User::factory()->create();
    $manager = User::factory()->create();
    $plain = User::factory()->create();
    $outAdmin = User::factory()->create();
    $otherUnit = Unit::create(['name' => 'واحد دیگر سه']);
    $admin->assignRole('admin');
    $outAdmin->assignRole('admin');
    $manager->givePermissionTo('manage_unit_tickets');
    foreach ([$author, $admin, $manager] as $u) {
        $u->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => false]);
    }
    $outAdmin->units()->attach($otherUnit->id, ['role' => 'responsible', 'is_primary' => false]);

    $comment = policyComment(policyTicket($this->unit->id, $author->id), $author->id);
    $policy = new TicketCommentPolicy();

    expect($policy->restore($admin, $comment))->toBeTrue()
        ->and($policy->restore($manager, $comment))->toBeTrue()
        ->and($policy->restore($plain, $comment))->toBeFalse()
        ->and($policy->restore($outAdmin, $comment))->toBeFalse();
});

test('policy forceDelete is admin-only regardless of scope or permissions', function () {
    $plain = User::factory()->create();
    $admin = User::factory()->create();
    $manager = User::factory()->create();
    $author = User::factory()->create();
    $admin->assignRole('admin');
    $manager->givePermissionTo('manage_unit_tickets');

    $comment = policyComment(policyTicket($this->unit->id, $author->id), $author->id);
    $policy = new TicketCommentPolicy();

    expect($policy->forceDelete($admin, $comment))->toBeTrue()
        ->and($policy->forceDelete($manager, $comment))->toBeFalse()
        ->and($policy->forceDelete($plain, $comment))->toBeFalse();
});

test('policy viewAny and create are open by design', function () {
    $user = User::factory()->create();
    $policy = new TicketCommentPolicy();

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue();
});