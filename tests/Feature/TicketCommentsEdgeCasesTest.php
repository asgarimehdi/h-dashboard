<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketCommentController;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(TicketCommentController::class);

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

    // ایشو #814: startEdit دیگر قاعله‌ی مالکیت را کپی نمی‌کند — فقط state
    // می‌گذارد؛ جلوی ویرایشِ غیرمجاز را saveEdit از راه policy می‌گیرد.
    public function test_save_edit_by_non_author_is_refused(): void
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
            ->set('editBody', 'Hacked')
            ->call('saveEdit')
            ->assertForbidden();

        $this->assertSame('Not mine', $comment->fresh()->body);
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
        DB::table('ticket_comments')
            ->where('id', $comment->id)
            ->update(['created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startEdit', $comment->id)
            ->set('editBody', 'Edited late')
            ->call('saveEdit')
            ->assertForbidden();

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
        // admin / manage_unit_tickets holder, so deleteComment must be refused
        // by TicketCommentPolicy (ایشو #814).
        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('deleteComment', $comment->id)
            ->assertForbidden();

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

    // ==================== ایشو #814 — عبور از تیکت (IDOR) ====================

    public function test_author_cannot_edit_comment_belonging_to_another_ticket(): void
    {
        $otherTicket = Ticket::create([
            'ticket_code' => 'TC-'.fake()->unique()->numerify('#####'),
            'subject' => 'Other Subject',
            'content' => 'Desc',
            'unit_id' => $this->unit->id,
            'user_id' => $this->user->id,
        ]);
        // همان نویسنده، تیکتِ دیگر — دقیقاً حفره‌ی IDORِ ایشو.
        $foreign = TicketComment::create([
            'ticket_id' => $otherTicket->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'body' => 'کامنت تیکت دیگر',
        ]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startEdit', $foreign->id)
            ->set('editBody', 'ویرایش غیرمجاز')
            ->call('saveEdit')
            ->assertForbidden();

        $this->assertSame('کامنت تیکت دیگر', $foreign->fresh()->body);
    }

    public function test_reply_to_comment_belonging_to_another_ticket_is_refused(): void
    {
        $otherTicket = Ticket::create([
            'ticket_code' => 'TC-'.fake()->unique()->numerify('#####'),
            'subject' => 'Other Subject',
            'content' => 'Desc',
            'unit_id' => $this->unit->id,
            'user_id' => $this->user->id,
        ]);
        $foreignParent = TicketComment::create([
            'ticket_id' => $otherTicket->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'body' => 'والد تیکت دیگر',
        ]);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->set('replyBody', 'پاسخ غیرمجاز')
            ->call('addReply', $foreignParent->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('ticket_comments', ['body' => 'پاسخ غیرمجاز']);
    }

    public function test_manage_unit_tickets_holder_cannot_delete_comment_on_out_of_scope_ticket(): void
    {
        $this->seed(PermissionSeeder::class);

        $foreignUnit = Unit::create(['name' => 'واحد دیگر']);
        $foreignTicket = Ticket::create([
            'ticket_code' => 'TC-'.fake()->unique()->numerify('#####'),
            'subject' => 'Out of scope',
            'content' => 'Desc',
            'unit_id' => $foreignUnit->id,
            'user_id' => $this->user->id,
        ]);
        $comment = TicketComment::create([
            'ticket_id' => $foreignTicket->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'body' => 'خارج از محدوده',
        ]);

        // دارنده‌ی `manage_unit_tickets` خارج از زیردرختِ خودش نباید حذف کند.
        $this->user->givePermissionTo('manage_unit_tickets');

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('deleteComment', $comment->id)
            ->assertForbidden();

        $this->assertDatabaseHas('ticket_comments', ['id' => $comment->id]);
    }
}
