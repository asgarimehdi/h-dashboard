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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TicketComment::class);

class TicketCommentsLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected $unit;

    protected $user;

    protected $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLookupTables();
        Session::flush();

        $tId = DB::table('tahsils')->first()->id;
        $eId = DB::table('estekhdams')->first()->id;
        $sId = DB::table('semats')->first()->id;
        $rId = DB::table('radifs')->first()->id;

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

    public function test_component_renders(): void
    {
        Livewire::test('tickets.ticket-comments')
            ->assertOk();
    }

    public function test_can_add_comment_via_livewire(): void
    {
        Livewire::test('tickets.ticket-comments')
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

    // #1176: `refreshComments()` MUST survive the conversion of `$ticket` to
    // a computed — deleting it (as the plan's step 3 once said) pushes every
    // freshly written comment behind the next request. The new body is
    // `unset($this->ticket)`, and THIS is the rendered proof it still works:
    // the brand new comment is in the modal's HTML in the same round-trip.
    public function test_add_comment_shows_the_new_comment_in_the_html_immediately(): void
    {
        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->set('body', 'کامنت تازه بی‌درنگ')
            ->call('addComment')
            ->assertSee('کامنت تازه بی‌درنگ');
    }

    public function test_can_reply_via_livewire(): void
    {
        $parent = TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'والد',
        ]);

        Livewire::test('tickets.ticket-comments')
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

        Livewire::test('tickets.ticket-comments')
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

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('deleteComment', $comment->id);

        $this->assertSoftDeleted('ticket_comments', ['id' => $comment->id]);
    }

    // ─── #1521: the render must not trust the stored `body_html` column ──────

    /**
     * The backup restore (RestoreService) writes `ticket_comments` rows
     * verbatim from an uploaded archive, so a stored `body_html` can carry
     * anything. The thread must render `body` — the single source of truth —
     * and never the column.
     */
    public function test_render_does_not_trust_stored_body_html(): void
    {
        $payload = '<script>alert(1)</script>';
        $comment = $this->makeComment($payload);
        $this->poisonBodyHtml($comment, $payload);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->assertDontSee($payload, false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_render_does_not_trust_stored_body_html_in_replies(): void
    {
        $payload = '<script>alert(1)</script>';
        $parent = $this->makeComment('والد');
        $reply = $this->makeComment($payload, $parent->id);
        $this->poisonBodyHtml($reply, $payload);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->assertDontSee($payload, false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    /**
     * Every shape of markup a restore (or any future writer that forgets to
     * escape) could deposit in the column, none of it reaches the page.
     */
    #[DataProvider('poisonedBodyHtmlProvider')]
    public function test_render_emits_none_of_the_stored_body_html_markup(string $payload): void
    {
        $comment = $this->makeComment('متن کامنت');
        $this->poisonBodyHtml($comment, $payload);

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->assertDontSee($payload, false)
            ->assertSee('متن کامنت');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function poisonedBodyHtmlProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'event handler' => ['<img src=x onerror="alert(1)">'],
            'javascript scheme' => ['<a href="javascript:alert(1)">x</a>'],
            'data scheme' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'vbscript scheme' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'file scheme' => ['<a href="file:///etc/passwd">x</a>'],
            'about scheme' => ['<a href="about:blank">x</a>'],
            'attribute breakout' => ['<a href="https://e.com" onmouseover="alert(1)">x</a>'],
        ];
    }

    // ─── #1521: the live writers keep `body` as the single source of truth ───

    public function test_add_comment_escapes_html_in_the_rendered_thread(): void
    {
        $payload = '<script>alert(1)</script>';

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->set('body', $payload)
            ->call('addComment')
            ->assertDontSee($payload, false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $this->ticket->id,
            'body' => $payload,
        ]);
    }

    public function test_add_reply_escapes_html_in_the_rendered_thread(): void
    {
        $payload = '<script>alert(1)</script>';
        $parent = $this->makeComment('والد');

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startReply', $parent->id)
            ->set('replyBody', $payload)
            ->call('addReply', $parent->id)
            ->assertDontSee($payload, false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $this->ticket->id,
            'parent_id' => $parent->id,
            'body' => $payload,
        ]);
    }

    public function test_save_edit_escapes_html_in_the_rendered_thread(): void
    {
        $payload = '<script>alert(1)</script>';
        $comment = $this->makeComment('قبل');

        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->call('startEdit', $comment->id)
            ->set('editBody', $payload)
            ->call('saveEdit')
            ->assertDontSee($payload, false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->assertDatabaseHas('ticket_comments', [
            'id' => $comment->id,
            'body' => $payload,
        ]);
    }

    /**
     * `body_html` left `$fillable` (#1521): no writer mass-assigns the column
     * any more, so it stays NULL for new rows and nothing can poison it
     * through the model.
     */
    public function test_livewire_writers_do_not_populate_body_html(): void
    {
        Livewire::test('tickets.ticket-comments')
            ->call('openForTicket', $this->ticket->id)
            ->set('body', 'کامنت تازه')
            ->call('addComment');

        $comment = TicketComment::where('ticket_id', $this->ticket->id)->firstOrFail();

        $this->assertNull($comment->body_html);
    }

    private function makeComment(string $body, ?int $parentId = null): TicketComment
    {
        return TicketComment::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'parent_id' => $parentId,
            'body' => $body,
        ]);
    }

    /**
     * Write the column directly, the way a backup restore does — bypassing
     * every Eloquent writer and the `$fillable` fence.
     */
    private function poisonBodyHtml(TicketComment $comment, string $bodyHtml): void
    {
        DB::table('ticket_comments')->where('id', $comment->id)->update(['body_html' => $bodyHtml]);
    }
}
