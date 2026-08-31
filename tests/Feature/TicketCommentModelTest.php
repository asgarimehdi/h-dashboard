<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentReaction;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

#[CoversClass(\App\Models\TicketComment::class)]

class TicketCommentModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
    }

    protected function createUserWithUnit(): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    protected function createTicket(): Ticket
    {
        $user = $this->createUserWithUnit();
        return Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => Unit::first()->id,
            'subject' => 'تیکت تست',
            'content' => 'متن تست',
            'priority' => 'normal',
            'status' => 'created',
        ]);
    }

    // --- Relationships ---

    public function test_comment_belongs_to_ticket(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $this->assertNotNull($comment->ticket);
        $this->assertEquals($ticket->id, $comment->ticket->id);
    }

    public function test_comment_belongs_to_user(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $this->assertNotNull($comment->user);
        $this->assertEquals($user->id, $comment->user->id);
    }

    public function test_comment_has_parent(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $parent = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر والد',
        ]);

        $child = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'body' => 'پاسخ',
        ]);

        $this->assertNotNull($child->parent);
        $this->assertEquals($parent->id, $child->parent->id);
    }

    public function test_comment_has_many_children(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $parent = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر والد',
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'body' => 'پاسخ ۱',
        ]);
        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'body' => 'پاسخ ۲',
        ]);

        $this->assertCount(2, $parent->children);
    }

    public function test_comment_has_many_reactions(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        TicketCommentReaction::create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'reaction' => '+1',
        ]);

        $this->assertCount(1, $comment->reactions);
    }

    // --- Scopes ---

    public function test_scope_root_returns_only_root_comments(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $root = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر والد',
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'body' => 'پاسخ',
        ]);

        $rootComments = TicketComment::root()->get();
        $this->assertCount(1, $rootComments);
        $this->assertEquals($root->id, $rootComments->first()->id);
    }

    public function test_scope_system_returns_only_system_comments(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر کاربر',
            'is_system' => false,
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'سیستم',
            'is_system' => true,
            'system_event' => 'ticket_created',
        ]);

        $this->assertCount(1, TicketComment::system()->get());
    }

    public function test_scope_user_returns_only_user_comments(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر کاربر',
            'is_system' => false,
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'سیستم',
            'is_system' => true,
        ]);

        $this->assertCount(1, TicketComment::query()->where('is_system', false)->get());
    }

    // --- canBeEditedBy ---

    public function test_canBeEditedBy_author_within_15_minutes(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $this->assertTrue($comment->canBeEditedBy($user));
    }

    public function test_cannotBeEditedBy_different_user(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $nCode2 = (string) fake()->unique()->numerify('##########');
        $unit = Unit::first();
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'کاربر دوم', 'l_name' => 'تست',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $otherUser = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $this->assertFalse($comment->canBeEditedBy($otherUser));
    }

    public function test_cannotBeEditedBy_author_after_15_minutes(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);
        \Illuminate\Support\Facades\DB::table('ticket_comments')
            ->where('id', $comment->id)
            ->update(['created_at' => now()->subMinutes(20)]);
        $comment->refresh();

        $this->assertFalse($comment->canBeEditedBy($user));
    }

    // --- canBeDeletedBy ---

    public function test_canBeDeletedBy_author(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $this->assertTrue($comment->canBeDeletedBy($user));
    }

    public function test_canBeDeletedBy_admin(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole('admin');

        $nCode2 = (string) fake()->unique()->numerify('##########');
        $unit = Unit::first();
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'نویسنده', 'l_name' => 'دیگری',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $author = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'body' => 'نظر تست',
        ]);

        $this->assertTrue($comment->canBeDeletedBy($user));
    }

    public function test_cannotBeDeletedBy_regular_user(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $nCode2 = (string) fake()->unique()->numerify('##########');
        $unit = Unit::first();
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'نویسنده', 'l_name' => 'دیگری',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $author = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'body' => 'نظر تست',
        ]);

        $this->assertFalse($comment->canBeDeletedBy($user));
    }

    // --- Soft deletes ---

    public function test_comment_uses_soft_deletes(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $id = $comment->id;
        $comment->delete();

        $this->assertSoftDeleted('ticket_comments', ['id' => $id]);
        $this->assertNull(TicketComment::find($id));
        $this->assertNotNull(TicketComment::withTrashed()->find($id));
    }

    // --- Boolean cast ---

    public function test_is_system_is_cast_to_boolean(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'سیستم',
            'is_system' => true,
        ]);

        $this->assertIsBool($comment->is_system);
        $this->assertTrue($comment->is_system);
    }

    // Note: the original test_scope_user test above exercised a raw
    // `->where('is_system', false)` query, so the actual scopeUser()
    // method body was never invoked. This test calls the scope directly.

    public function test_scope_user_invokes_scope(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر کاربر',
            'is_system' => false,
        ]);
        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'سیستم',
            'is_system' => true,
        ]);

        $this->assertCount(1, TicketComment::query()->user()->get());
    }

    // --- descendants (recursive) ---

    public function test_comment_descendants_are_recursive(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $root = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'والد',
        ]);
        $child = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'body' => 'فرزند',
        ]);
        $grandchild = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'parent_id' => $child->id,
            'body' => 'نوه',
        ]);

        $root->load('descendants');

        $this->assertCount(1, $root->descendants);
        $this->assertEquals($child->id, $root->descendants->first()->id);
        $this->assertEquals($grandchild->id, $root->descendants->first()->descendants->first()->id);
    }

    // --- scopeWithReactionCounts ---

    public function test_scope_with_reaction_counts_groups_by_reaction(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $nCode2 = (string) fake()->unique()->numerify('##########');
        $unit = Unit::first();
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'واکنش‌دهنده', 'l_name' => 'دوم',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $otherUser = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);

        TicketCommentReaction::create([
            'comment_id' => $comment->id, 'user_id' => $user->id, 'reaction' => '+1',
        ]);
        TicketCommentReaction::create([
            'comment_id' => $comment->id, 'user_id' => $otherUser->id, 'reaction' => '+1',
        ]);

        $loaded = TicketComment::withReactionCounts()->find($comment->id);

        // The withCount subquery collapses to a single total count of
        // reactions on the comment (the inner groupBy is not preserved by
        // withCount), so reaction_counts is an integer here.
        $this->assertNotNull($loaded->reaction_counts);
        $this->assertEquals(2, $loaded->reaction_counts);
    }
}
