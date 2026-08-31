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
use Tests\TestCase;

/** @covers \App\Models\TicketComment */
class TicketCommentsRefreshTest extends TestCase
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
        Person::create(['n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'محمدی', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $this->unit->id]);
        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->actingAs($this->user);

        $this->ticket = Ticket::create([
            'ticket_code' => 'TC-'.fake()->unique()->numerify('#####'),
            'subject' => 'Test', 'content' => 'Desc',
            'unit_id' => $this->unit->id, 'user_id' => $this->user->id,
        ]);
    }

    public function test_new_comment_shows_immediately(): void
    {
        $component = Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->set('body', 'کامنت جدید');

        // Before adding: comments count should be 0
        $component->assertSeeHtml('هنوز کامنتی ثبت نشده است.');

        $component->call('addComment');

        // After adding: the new comment should be visible without reopening
        $component->assertSee('کامنت جدید');
        $component->assertDontSee('هنوز کامنتی ثبت نشده است.');
    }

    public function test_reply_shows_immediately(): void
    {
        $component = Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startReply', 0); // hmm, no parent yet; add one first

        // Actually add a parent first
        $component->call('openForTicket', $this->ticket->id)
            ->set('body', 'والد')
            ->call('addComment');

        $parent = TicketComment::where('ticket_id', $this->ticket->id)->first();

        $component->call('startReply', $parent->id)
            ->set('replyBody', 'پاسخ')
            ->call('addReply', $parent->id);

        $component->assertSee('پاسخ');
    }

    public function test_delete_removes_immediately(): void
    {
        $comment = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'برای حذف',
        ]);

        $component = Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id);

        // Confirm the comment is loaded and visible
        $this->assertNotNull($component->get('ticket'));
        $this->assertEquals(1, $component->get('ticket')['comments']->count());

        $component->call('deleteComment', $comment->id);

        // After delete, ticket comments should be reloaded (0 remaining)
        $this->assertEquals(0, $component->get('ticket')['comments']->count());
    }
}
