<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentReaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Comprehensive API coverage for the Ticket Comments system (issue #222).
 * Covers every controller endpoint + edge cases the base tests miss:
 * scope enforcement, validation, thread depth, markdown, notifications,
 * reactions listing/idempotency, admin delete, 15-min edit window.
 */
covers(\App\Http\Controllers\Api\TicketCommentController::class);

class TicketCommentApiComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected $unit;

    protected $user;

    protected $ticket;

    protected $otherUnit;

    protected $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        // Ensure permissions and roles exist for testing (Issue #323)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'create_ticket']);
        Permission::firstOrCreate(['name' => 'view_assigned_tickets']);
        Permission::firstOrCreate(['name' => 'view_all_tickets']);
        Permission::firstOrCreate(['name' => 'manage_unit_tickets']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions(Permission::all());

        $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
        $sId = DB::table('semats')->insertGetId(['name' => 'S']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'R']);

        $this->unit = Unit::create(['name' => 'Unit A']);
        $this->otherUnit = Unit::create(['name' => 'Unit B']);

        // User A in unit A
        $nCodeA = (string) fake()->unique()->numerify('##########');
        Person::create(['n_code' => $nCodeA, 'f_name' => 'علی', 'l_name' => 'محمدی', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $this->unit->id]);
        $this->user = User::create(['n_code' => $nCodeA, 'password' => Hash::make('password')]);
        $this->user->assignRole('admin');
        $this->user->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);

        // User B in unit B (out of scope)
        $nCodeB = (string) fake()->unique()->numerify('##########');
        Person::create(['n_code' => $nCodeB, 'f_name' => 'رضا', 'l_name' => 'احمدی', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $this->otherUnit->id]);
        $this->otherUser = User::create(['n_code' => $nCodeB, 'password' => Hash::make('password')]);
        $this->otherUser->assignRole('admin');
        $this->otherUser->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->otherUser->units()->attach($this->otherUnit->id, ['role' => 'staff', 'is_primary' => true]);

        Session::put('current_unit_id', $this->unit->id);

        $this->ticket = Ticket::create([
            'ticket_code' => 'TC-'.fake()->unique()->numerify('#####'),
            'subject' => 'Test', 'content' => 'Desc',
            'unit_id' => $this->unit->id, 'user_id' => $this->user->id,
        ]);
    }

    protected function authAsUserA(): void
    {
        $this->actingAs($this->user, 'sanctum');
        Session::put('current_unit_id', $this->unit->id);
    }

    protected function authAsUserB(): void
    {
        $this->actingAs($this->otherUser, 'sanctum');
        Session::put('current_unit_id', $this->otherUnit->id);
    }

    protected function makeComment(array $attrs = []): TicketComment
    {
        return TicketComment::create(array_merge([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'بدنه کامنت',
            'body_html' => '<p>بدنه کامنت</p>',
        ], $attrs));
    }

    // ─── Index ───────────────────────────────────────────────────────────

    public function test_index_lists_only_root_comments(): void
    {
        $this->authAsUserA();
        $root = $this->makeComment(['body' => 'ریشه']);
        $this->makeComment(['body' => 'ریشه ۲']);
        $this->makeComment(['body' => 'پاسخ', 'parent_id' => $root->id]);

        $response = $this->getJson("/api/tickets/{$this->ticket->id}/comments");
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('meta.total')); // only roots
    }

    public function test_index_threaded_includes_children(): void
    {
        $this->authAsUserA();
        $root = $this->makeComment(['body' => 'ریشه']);
        $this->makeComment(['body' => 'پاسخ', 'parent_id' => $root->id]);

        $response = $this->getJson("/api/tickets/{$this->ticket->id}/comments?threaded=true");
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['children']);
    }

    public function test_index_out_of_scope_403(): void
    {
        $this->authAsUserB();
        $this->getJson("/api/tickets/{$this->ticket->id}/comments")
            ->assertStatus(403);
    }

    // ─── Store ───────────────────────────────────────────────────────────

    public function test_store_requires_body(): void
    {
        $this->authAsUserA();
        $this->postJson("/api/tickets/{$this->ticket->id}/comments", [])
            ->assertStatus(422);
    }

    public function test_store_rejects_parent_from_other_ticket(): void
    {
        $this->authAsUserA();
        $otherTicket = Ticket::create([
            'ticket_code' => 'TC-X'.fake()->unique()->numerify('####'),
            'subject' => 'Other', 'content' => 'X',
            'unit_id' => $this->unit->id, 'user_id' => $this->user->id,
        ]);
        $foreignComment = $this->makeComment(['ticket_id' => $otherTicket->id]);

        $this->postJson("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'پاسخ به غریبه',
            'parent_id' => $foreignComment->id,
        ])->assertStatus(422);
    }

    public function test_store_enforces_max_thread_depth_3(): void
    {
        $this->authAsUserA();
        $c1 = $this->makeComment(['body' => 'سطح ۱']);
        $c2 = $this->makeComment(['body' => 'سطح ۲', 'parent_id' => $c1->id]);
        $c3 = $this->makeComment(['body' => 'سطح ۳', 'parent_id' => $c2->id]);
        $c4 = $this->makeComment(['body' => 'سطح ۴', 'parent_id' => $c3->id]);

        // Depth of c4 = 3 parents (c3→c2→c1); replying to c4 must fail
        $this->postJson("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سطح ۵ ممنوع',
            'parent_id' => $c4->id,
        ])->assertStatus(422);

        // Replying to c3 (depth 2) is still allowed
        $this->postJson("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سطح ۴ مجاز',
            'parent_id' => $c3->id,
        ])->assertStatus(201);
    }

    public function test_store_processes_markdown(): void
    {
        $this->authAsUserA();
        $response = $this->postJson("/api/tickets/{$this->ticket->id}/comments", [
            'body' => '**bold** و `code` و [لینک](https://example.com)',
        ]);
        $response->assertStatus(201);
        $html = $response->json('data.body_html');
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('<a href="https://example.com"', $html);
    }

    public function test_store_out_of_scope_403(): void
    {
        $this->authAsUserB();
        $this->postJson("/api/tickets/{$this->ticket->id}/comments", ['body' => 'x'])
            ->assertStatus(403);
    }

    // ─── Show ────────────────────────────────────────────────────────────

    public function test_show_returns_comment(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();
        $this->getJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $comment->id);
    }

    public function test_show_comment_from_other_ticket_403(): void
    {
        $this->authAsUserA();
        $otherTicket = Ticket::create([
            'ticket_code' => 'TC-Y'.fake()->unique()->numerify('####'),
            'subject' => 'Other', 'content' => 'Y',
            'unit_id' => $this->unit->id, 'user_id' => $this->user->id,
        ]);
        $foreign = $this->makeComment(['ticket_id' => $otherTicket->id]);

        $this->getJson("/api/tickets/{$this->ticket->id}/comments/{$foreign->id}")
            ->assertStatus(403);
    }

    // ─── Update ──────────────────────────────────────────────────────────

    public function test_update_after_15min_403(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();
        // Simulate 16 minutes passed
        TicketComment::where('id', $comment->id)->update([
            'created_at' => now()->subMinutes(16),
        ]);

        $this->putJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", [
            'body' => 'ویرایش دیرهنگام',
        ])->assertStatus(403);
    }

    public function test_update_by_other_user_403(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->authAsUserB();
        $this->putJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", [
            'body' => 'دزدی',
        ])->assertStatus(403);
    }

    public function test_update_by_author_within_15min_ok(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->putJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", [
            'body' => 'ویرایش درست',
        ])->assertStatus(200)
            ->assertJsonPath('data.body', 'ویرایش درست');
    }

    // ─── Destroy ─────────────────────────────────────────────────────────

    public function test_destroy_by_admin_ok(): void
    {
        $this->authAsUserA();
        // Ensure the admin role exists (needed for canBeDeletedBy hasRole('admin'))
        if (! Role::where('name', 'admin')->exists()) {
            Role::firstOrCreate(['name' => 'admin']);
        }
        $this->user->assignRole('admin');
        $comment = $this->makeComment();

        $this->deleteJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('ticket_comments', ['id' => $comment->id]);
    }

    public function test_destroy_by_other_user_403(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->authAsUserB();
        $this->deleteJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}")
            ->assertStatus(403);
    }

    // ─── Reactions ───────────────────────────────────────────────────────

    public function test_react_is_idempotent(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->postJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'])
            ->assertStatus(200);
        $this->postJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'])
            ->assertStatus(200);

        $this->assertEquals(1, TicketCommentReaction::where('comment_id', $comment->id)->count());
    }

    public function test_react_invalid_reaction_422(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->postJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => '🤔'])
            ->assertStatus(422);
    }

    public function test_unreact_removes_reaction(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();
        TicketCommentReaction::create([
            'comment_id' => $comment->id,
            'user_id' => $this->user->id,
            'reaction' => 'rocket',
        ]);

        $this->deleteJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'rocket'])
            ->assertStatus(200);

        $this->assertEquals(0, TicketCommentReaction::where('comment_id', $comment->id)->count());
    }

    public function test_reactions_lists_counts_and_users(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();
        TicketCommentReaction::create(['comment_id' => $comment->id, 'user_id' => $this->user->id, 'reaction' => '+1']);
        TicketCommentReaction::create(['comment_id' => $comment->id, 'user_id' => $this->otherUser->id, 'reaction' => '+1']);
        TicketCommentReaction::create(['comment_id' => $comment->id, 'user_id' => $this->user->id, 'reaction' => 'eyes']);

        $response = $this->getJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/reactions");
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(2, $data['+1']['count']);
        $this->assertEquals(1, $data['eyes']['count']);
        $this->assertCount(2, $data['+1']['users']);
    }

    public function test_react_out_of_scope_403(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->authAsUserB();
        $this->postJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'])
            ->assertStatus(403);
    }

    // ─── Notifications ───────────────────────────────────────────────────

    public function test_reply_notifies_parent_author(): void
    {
        $this->authAsUserA();
        $parent = $this->makeComment(['body' => 'والد']);

        // Other user replies to A's comment (from a ticket in B's scope? No —
        // same ticket, but user B can't access it. Use a second user in unit A.)
        $nCodeC = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCodeC, 'f_name' => 'سارا', 'l_name' => 'کریمی',
            't_id' => DB::table('tahsils')->insertGetId(['name' => 'T3']),
            'e_id' => DB::table('estekhdams')->insertGetId(['name' => 'E3']),
            's_id' => DB::table('semats')->insertGetId(['name' => 'S3']),
            'r_id' => DB::table('radifs')->insertGetId(['name' => 'R3']),
            'u_id' => $this->unit->id,
        ]);
        $userC = User::create(['n_code' => $nCodeC, 'password' => Hash::make('password')]);
        $userC->assignRole('admin');
        $userC->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $userC->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        $this->actingAs($userC, 'sanctum');

        $this->postJson("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'پاسخ به علی',
            'parent_id' => $parent->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
        ]);
    }

    public function test_mention_creates_notification(): void
    {
        $this->authAsUserA();

        // Mention user B by n_code
        $this->postJson("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سلام @'.$this->otherUser->n_code.' لطفا ببین',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->otherUser->id,
        ]);
    }

    public function test_reaction_notifies_comment_author(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment(['body' => 'کامنت علی']);

        // User B can't access; use user C in unit A
        $nCodeC = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCodeC, 'f_name' => 'سارا', 'l_name' => 'کریمی',
            't_id' => DB::table('tahsils')->insertGetId(['name' => 'T3']),
            'e_id' => DB::table('estekhdams')->insertGetId(['name' => 'E3']),
            's_id' => DB::table('semats')->insertGetId(['name' => 'S3']),
            'r_id' => DB::table('radifs')->insertGetId(['name' => 'R3']),
            'u_id' => $this->unit->id,
        ]);
        $userC = User::create(['n_code' => $nCodeC, 'password' => Hash::make('password')]);
        $userC->assignRole('admin');
        $userC->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $userC->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        $this->actingAs($userC, 'sanctum');

        $this->postJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'])
            ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
        ]);
    }
}
