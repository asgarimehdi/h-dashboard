<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\Api\TicketCommentController::class)]

class TicketCommentsEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected $unit;
    protected $user;
    protected $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
        $sId = DB::table('semats')->insertGetId(['name' => 'S']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'R']);

        $this->unit = Unit::create(['name' => 'Test Unit']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'محمدی',
            't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->actingAs($this->user);

        $this->ticket = Ticket::create([
            'ticket_code' => 'TC-'.fake()->unique()->numerify('#####'),
            'subject' => 'Test Subject',
            'content' => 'Desc',
            'unit_id' => $this->unit->id,
            'user_id' => $this->user->id,
        ]);
    }

    protected function makeUserWithPerson()
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'دیگر', 'l_name' => 'کاربر',
            't_id' => DB::table('tahsils')->insertGetId(['name' => 'T2']),
            'e_id' => DB::table('estekhdams')->insertGetId(['name' => 'E2']),
            's_id' => DB::table('semats')->insertGetId(['name' => 'S2']),
            'r_id' => DB::table('radifs')->insertGetId(['name' => 'R2']),
            'u_id' => $this->unit->id,
        ]);

        return User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    }

    public function test_close_resets_state(): void
    {
        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->assertSet('showModal', true)
            ->call('close')
            ->assertSet('showModal', false)
            ->assertSet('ticketId', null);
    }

    public function test_cancel_reply_resets_reply_state(): void
    {
        $parent = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'body' => 'Parent',
        ]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startReply', $parent->id)
            ->assertSet('replyToId', $parent->id)
            ->call('cancelReply')
            ->assertSet('replyToId', null)
            ->assertSet('replyBody', '');
    }

    public function test_cancel_edit_resets_edit_state(): void
    {
        $comment = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'body' => 'Editable',
        ]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startEdit', $comment->id)
            ->assertSet('editing', true)
            ->call('cancelEdit')
            ->assertSet('editing', false)
            ->assertSet('editCommentId', null);
    }

    public function test_add_comment_requires_body(): void
    {
        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->set('body', '')
            ->call('addComment')
            ->assertHasErrors(['body']);

        $this->assertDatabaseCount('ticket_comments', 0);
    }

    public function test_load_ticket_with_null_id_sets_ticket_null(): void
    {
        Livewire::test('tickets.ticket-comments')
            ->call('loadTicket')
            ->assertSet('ticket', null);
    }

    public function test_start_edit_by_non_author_is_ignored(): void
    {
        $otherUser = $this->makeUserWithPerson();
        $comment = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $otherUser->id,
            'parent_id' => null,
            'body' => 'Not mine',
        ]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startEdit', $comment->id)
            ->assertSet('editCommentId', null);
    }

    public function test_save_edit_after_15_minutes_is_rejected(): void
    {
        $comment = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'body' => 'Old comment',
        ]);
        // Simulate the comment being 20 minutes old directly in the DB.
        \Illuminate\Support\Facades\DB::table('ticket_comments')
            ->where('id', $comment->id)
            ->update(['created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startEdit', $comment->id)
            ->set('editBody', 'Edited late')
            ->call('saveEdit');

        $this->assertSame('Old comment', $comment->fresh()->body);
    }

    public function test_non_author_without_permission_cannot_delete_comment(): void
    {
        $otherUser = $this->makeUserWithPerson();
        $comment = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $otherUser->id,
            'parent_id' => null,
            'body' => 'Someone else',
        ]);

        // $this->user is the authenticated user but neither the author nor an
        // admin / manage_unit_tickets holder, so deleteComment must be a no-op.
        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('deleteComment', $comment->id);

        $this->assertDatabaseHas('ticket_comments', ['id' => $comment->id]);
    }

    public function test_refresh_comments_reloads_ticket(): void
    {
        TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'body' => 'Refresh me',
        ]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('refreshComments')
            ->assertSet('ticket', fn ($ticket) => $ticket && $ticket->comments->count() === 1);
    }
}
