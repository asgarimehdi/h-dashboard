<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketCommentController;
use App\Models\Notification;
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
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

/**
 * Comprehensive API coverage for the Ticket Comments system (issue #222).
 * Covers every controller endpoint + edge cases the base tests miss:
 * scope enforcement, validation, thread depth, markdown, notifications,
 * reactions listing/idempotency, admin delete, 15-min edit window.
 */
covers(TicketCommentController::class);

class TicketCommentApiComprehensiveTest extends TestCase
{
    use InteractsWithApiTokens;
    use RefreshDatabase;

    protected $unit;

    protected $user;

    protected $ticket;

    protected $otherUnit;

    protected $otherUser;

    protected $authToken;

    /**
     * Lookup-table ids, kept so a test can build extra persons without
     * re-inserting (and without colliding on) the seeded rows.
     */
    protected array $lookupIds = [];

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

        $this->lookupIds = compact('tId', 'eId', 'sId', 'rId');

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
        $this->authToken = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        Session::put('current_unit_id', $this->unit->id);
    }

    protected function authAsUserB(): void
    {
        $this->authToken = $this->createApiToken($this->otherUser, ['tickets:read', 'tickets:write']);
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

    /**
     * Create a user whose person lives in `$unitId`, with the permissions the
     * comment routes need. Issue #863 tests need a controllable number of
     * in-scope recipients, so this is factored out instead of hand-inserting
     * persons 30 times.
     */
    protected function makeScopedUser(int $unitId): User
    {
        $nCode = (string) fake()->unique()->numerify('##########');

        Person::create([
            'n_code' => $nCode,
            'f_name' => 'کاربر',
            'l_name' => 'آزمایشی',
            't_id' => $this->lookupIds['tId'],
            'e_id' => $this->lookupIds['eId'],
            's_id' => $this->lookupIds['sId'],
            'r_id' => $this->lookupIds['rId'],
            'u_id' => $unitId,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->assignRole('admin');
        $user->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user->units()->attach($unitId, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    // ─── Index ───────────────────────────────────────────────────────────

    public function test_index_lists_only_root_comments(): void
    {
        $this->authAsUserA();
        $root = $this->makeComment(['body' => 'ریشه']);
        $this->makeComment(['body' => 'ریشه ۲']);
        $this->makeComment(['body' => 'پاسخ', 'parent_id' => $root->id]);

        $response = $this->apiGet("/api/tickets/{$this->ticket->id}/comments", $this->authToken);
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('meta.total')); // only roots
    }

    public function test_index_threaded_includes_children(): void
    {
        $this->authAsUserA();
        $root = $this->makeComment(['body' => 'ریشه']);
        $this->makeComment(['body' => 'پاسخ', 'parent_id' => $root->id]);

        $response = $this->apiGet("/api/tickets/{$this->ticket->id}/comments?threaded=true", $this->authToken);
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['children']);
    }

    public function test_index_out_of_scope_403(): void
    {
        $this->authAsUserB();
        $this->apiGet("/api/tickets/{$this->ticket->id}/comments", $this->authToken)
            ->assertStatus(403);
    }

    // ─── Store ───────────────────────────────────────────────────────────

    public function test_store_requires_body(): void
    {
        $this->authAsUserA();
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [], $this->authToken)
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

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'پاسخ به غریبه',
            'parent_id' => $foreignComment->id,
        ], $this->authToken)->assertStatus(422);
    }

    public function test_store_enforces_max_thread_depth_3(): void
    {
        $this->authAsUserA();
        $c1 = $this->makeComment(['body' => 'سطح ۱']);
        $c2 = $this->makeComment(['body' => 'سطح ۲', 'parent_id' => $c1->id]);
        $c3 = $this->makeComment(['body' => 'سطح ۳', 'parent_id' => $c2->id]);
        $c4 = $this->makeComment(['body' => 'سطح ۴', 'parent_id' => $c3->id]);

        // Depth of c4 = 3 parents (c3→c2→c1); replying to c4 must fail
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سطح ۵ ممنوع',
            'parent_id' => $c4->id,
        ], $this->authToken)->assertStatus(422);

        // Replying to c3 (depth 2) is still allowed
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سطح ۴ مجاز',
            'parent_id' => $c3->id,
        ], $this->authToken)->assertStatus(201);
    }

    public function test_store_processes_markdown(): void
    {
        $this->authAsUserA();
        $response = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => '**bold** و `code` و [لینک](https://example.com)',
        ], $this->authToken);
        $response->assertStatus(201);
        $html = $response->json('data.body_html');
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('<a href="https://example.com"', $html);
    }

    public function test_store_out_of_scope_403(): void
    {
        $this->authAsUserB();
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'x'], $this->authToken)
            ->assertStatus(403);
    }

    // ─── Show ────────────────────────────────────────────────────────────

    public function test_show_returns_comment(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();
        $this->apiGet("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", $this->authToken)
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

        $this->apiGet("/api/tickets/{$this->ticket->id}/comments/{$foreign->id}", $this->authToken)
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

        $this->apiPut("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", [
            'body' => 'ویرایش دیرهنگام',
        ], $this->authToken)->assertStatus(403);
    }

    public function test_update_by_other_user_403(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->authAsUserB();
        $this->apiPut("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", [
            'body' => 'دزدی',
        ], $this->authToken)->assertStatus(403);
    }

    public function test_update_by_author_within_15min_ok(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->apiPut("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", [
            'body' => 'ویرایش درست',
        ], $this->authToken)->assertStatus(200)
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

        $this->apiDelete("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", $this->authToken)
            ->assertStatus(200);

        $this->assertSoftDeleted('ticket_comments', ['id' => $comment->id]);
    }

    public function test_destroy_by_other_user_403(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->authAsUserB();
        $this->apiDelete("/api/tickets/{$this->ticket->id}/comments/{$comment->id}", $this->authToken)
            ->assertStatus(403);
    }

    // ─── Reactions ───────────────────────────────────────────────────────

    public function test_react_is_idempotent(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'], $this->authToken)
            ->assertStatus(200);
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'], $this->authToken)
            ->assertStatus(200);

        $this->assertEquals(1, TicketCommentReaction::where('comment_id', $comment->id)->count());
    }

    public function test_react_invalid_reaction_422(): void
    {
        $this->authAsUserA();
        $comment = $this->makeComment();

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => '🤔'], $this->authToken)
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

        $this->forgetGuards();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->authToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'rocket'])
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

        $response = $this->apiGet("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/reactions", $this->authToken);
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
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'], $this->authToken)
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

        $tokenC = $this->createApiToken($userC, ['tickets:read', 'tickets:write']);

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'پاسخ به علی',
            'parent_id' => $parent->id,
        ], $tokenC)->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
        ]);
    }

    public function test_mention_creates_notification(): void
    {
        $this->authAsUserA();

        // Issue #863: a mention target must live inside the AUTHOR's
        // accessible units. This test used to mention `$this->otherUser` — a
        // user of unit B, out of the author's scope — and asserted a
        // notification, which pinned the exact defect the issue reports.
        // It now mentions a second user of the SAME unit.
        $inScopeUser = $this->makeScopedUser($this->unit->id);

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سلام @'.$inScopeUser->n_code.' لطفا ببین',
        ], $this->authToken)->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $inScopeUser->id,
        ]);
    }

    // ─── Mention cap and unit filter (issue #863) ─────────────────────────

    /**
     * A body packed with distinct, real, IN-SCOPE handles must not notify
     * more than `MAX_MENTIONS` recipients.
     *
     * Before the fix there was no cap at all: `array_unique` keeps every
     * distinct handle, so the `whereIn('n_code', …)` carried all of them and
     * `notifyMentions()` dispatched one job per match. The handle count is
     * deliberately far above the ceiling of a max-length body (a max:10000
     * body fits ~909 `@nnnnnnnnnn` handles) so the test also pins that the
     * bound holds for any number of handles, not just a realistic one.
     */
    public function test_mention_flood_does_not_exceed_the_cap(): void
    {
        $this->authAsUserA();

        $recipients = collect(range(1, 30))
            ->map(fn (): User => $this->makeScopedUser($this->unit->id));

        $body = 'لطفا بررسی کنید: '.implode(' ', $recipients->map(fn (User $u): string => '@'.$u->n_code)->all());

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => $body,
        ], $this->authToken)->assertStatus(201);

        $this->assertSame(
            TicketCommentController::MAX_MENTIONS,
            Notification::query()->where('type', 'mention')->count(),
            'A mention flood must be capped, not one notification per handle.',
        );
    }

    /**
     * A handle belonging to a user OUTSIDE the author's accessible units must
     * not produce a notification — even though the same n_code resolves to a
     * real account and even though that account holds `create_ticket`.
     */
    public function test_mention_of_out_of_scope_user_creates_no_notification(): void
    {
        $this->authAsUserA();

        // `$this->otherUser` is a real account in unit B with the full
        // ticket permission set, and the ticket is in unit A.
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سلام @'.$this->otherUser->n_code.' لطفا ببین',
        ], $this->authToken)->assertStatus(201);

        $this->assertSame(
            0,
            Notification::query()->where('type', 'mention')->count(),
            'An out-of-unit mention must not notify anyone.',
        );
    }

    /**
     * A mention whose handle resolves to a real account whose person has NO
     * unit (`persons.u_id IS NULL`) must not notify either.
     *
     * This is the fail-closed half of the unit filter: `whereIn('u_id', …)`
     * does not match a NULL unit, so a unit-less person is "in scope of
     * nothing" rather than "in scope of everything". Before the fix this row
     * matched `User::whereIn('n_code', …)` and was notified.
     *
     * Note the actor's scope is NOT empty here (it holds unit A), so this
     * test is not satisfied by the pre-existing ticket-scope 403 — it
     * exercises the mention predicate itself.
     */
    public function test_mention_of_unit_less_user_creates_no_notification(): void
    {
        $this->authAsUserA();

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'بدون',
            'l_name' => 'واحد',
            't_id' => $this->lookupIds['tId'],
            'e_id' => $this->lookupIds['eId'],
            's_id' => $this->lookupIds['sId'],
            'r_id' => $this->lookupIds['rId'],
            'u_id' => null,
        ]);
        $unitLessUser = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'سلام @'.$unitLessUser->n_code,
        ], $this->authToken)->assertStatus(201);

        $this->assertSame(
            0,
            Notification::query()->where('type', 'mention')->count(),
            'A unit-less mention target must not be notified.',
        );
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

        $tokenC = $this->createApiToken($userC, ['tickets:read', 'tickets:write']);

        $this->apiPost("/api/tickets/{$this->ticket->id}/comments/{$comment->id}/react", ['reaction' => 'heart'], $tokenC)
            ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
        ]);
    }
}
