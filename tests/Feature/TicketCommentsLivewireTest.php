<?php

namespace Tests\Feature;

use App\Livewire\Tickets\TicketComments;
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
use Tests\TestCase;

class TicketCommentsLivewireTest extends TestCase
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
        $nCode = (string) random_int(1000000000, 2147483647);
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
            'ticket_code' => 'TC-' . random_int(10000, 99999),
            'subject' => 'Test Subject',
            'content' => 'Desc',
            'unit_id' => $this->unit->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_component_renders(): void
    {
        Livewire::test(TicketComments::class)
            ->assertOk();
    }

    public function test_can_add_comment_via_livewire(): void
    {
        Livewire::test(TicketComments::class)
            ->call('openForTicket', $this->ticket->id)
            ->assertSet('showModal', true)
            ->set('body', 'کامنت از وب')
            ->call('addComment')
            ->assertSet('body', '');

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'کامنت از وب',
        ]);
    }

    public function test_can_reply_via_livewire(): void
    {
        $parent = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'والد',
        ]);

        Livewire::test(TicketComments::class)
            ->call('openForTicket', $this->ticket->id)
            ->call('startReply', $parent->id)
            ->assertSet('replyToId', $parent->id)
            ->set('replyBody', 'پاسخ از وب')
            ->call('addReply', $parent->id)
            ->assertSet('replyToId', null);

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $this->ticket->id,
            'parent_id' => $parent->id,
            'body' => 'پاسخ از وب',
        ]);
    }

    public function test_author_can_edit_comment(): void
    {
        $comment = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'قبل',
        ]);

        Livewire::test(TicketComments::class)
            ->call('openForTicket', $this->ticket->id)
            ->call('startEdit', $comment->id)
            ->assertSet('editing', true)
            ->set('editBody', 'بعد')
            ->call('saveEdit')
            ->assertSet('editing', false);

        $this->assertDatabaseHas('ticket_comments', [
            'id' => $comment->id,
            'body' => 'بعد',
        ]);
    }

    public function test_author_can_delete_comment(): void
    {
        $comment = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'حذف',
        ]);

        Livewire::test(TicketComments::class)
            ->call('openForTicket', $this->ticket->id)
            ->call('deleteComment', $comment->id);

        $this->assertSoftDeleted('ticket_comments', ['id' => $comment->id]);
    }
}