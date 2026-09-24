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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

/**
 * Functional tests for the Ticket Comments & Threaded Discussion system
 * (issue #222 / PR #268).
 *
 * Covers: create, threaded reply, list, author-only edit (15 min window),
 * authorization (403 for non-author), reactions add/remove, soft delete.
 */
covers(TicketComment::class);

class TicketCommentTest extends TestCase
{
    use InteractsWithApiTokens;
    use RefreshDatabase;

    protected $unit;

    protected $user;

    protected $ticket;

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

        $this->unit = Unit::create(['name' => 'Test Unit']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'محمدی',
            't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->assignRole('admin');
        $this->user->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);

        $this->ticket = Ticket::create([
            'ticket_code' => 'TC-'.fake()->unique()->numerify('#####'),
            'subject' => 'Test Subject', 'content' => 'Desc',
            'unit_id' => $this->unit->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_create_comment(): void
    {
        $token = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'اولین کامنت',
        ], $token);

        $response->assertStatus(201)
            ->assertJsonPath('data.body', 'اولین کامنت');
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->user->id,
            'body' => 'اولین کامنت',
            'parent_id' => null,
        ]);
    }

    public function test_can_reply_to_comment(): void
    {
        $token = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        $parent = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'پدر'], $token)->json('data');

        $response = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", [
            'body' => 'پاسخ',
            'parent_id' => $parent['id'],
        ], $token);

        $response->assertStatus(201)
            ->assertJsonPath('data.parent_id', $parent['id']);
    }

    public function test_can_list_comments(): void
    {
        $token = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'یک'], $token);
        $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'دو'], $token);

        $response = $this->apiGet("/api/tickets/{$this->ticket->id}/comments", $token);
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_author_can_update_within_15min(): void
    {
        $token = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        $comment = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'قبل'], $token)->json('data');

        $response = $this->apiPut("/api/tickets/{$this->ticket->id}/comments/{$comment['id']}", [
            'body' => 'بعد',
        ], $token);
        $response->assertStatus(200)
            ->assertJsonPath('data.body', 'بعد');
    }

    public function test_other_user_cannot_update(): void
    {
        $token = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        $comment = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'قبل'], $token)->json('data');

        // Another user in same unit (needs a person row - FK constraint)
        $nCode2 = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'رضا', 'l_name' => 'احمدی',
            't_id' => DB::table('tahsils')->insertGetId(['name' => 'T2']),
            'e_id' => DB::table('estekhdams')->insertGetId(['name' => 'E2']),
            's_id' => DB::table('semats')->insertGetId(['name' => 'S2']),
            'r_id' => DB::table('radifs')->insertGetId(['name' => 'R2']),
            'u_id' => $this->unit->id,
        ]);
        $other = User::create(['n_code' => $nCode2, 'password' => Hash::make('x')]);
        $other->assignRole('admin');
        $other->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $other->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);

        $otherToken = $this->createApiToken($other, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$this->ticket->id}/comments/{$comment['id']}", [
            'body' => 'دزدی',
        ], $otherToken);
        $response->assertStatus(403);
    }

    public function test_can_add_and_remove_reaction(): void
    {
        $token = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        $comment = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'ریاکشن'], $token)->json('data');

        $response = $this->apiPost("/api/tickets/{$this->ticket->id}/comments/{$comment['id']}/react", [
            'reaction' => '+1',
        ], $token);
        $response->assertStatus(200);
        $this->assertDatabaseHas('ticket_comment_reactions', [
            'comment_id' => $comment['id'],
            'user_id' => $this->user->id,
            'reaction' => '+1',
        ]);

        $this->forgetGuards();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/tickets/{$this->ticket->id}/comments/{$comment['id']}/react", [
            'reaction' => '+1',
        ])->assertStatus(200);
        $this->assertDatabaseMissing('ticket_comment_reactions', [
            'comment_id' => $comment['id'],
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_soft_delete_comment(): void
    {
        $token = $this->createApiToken($this->user, ['tickets:read', 'tickets:write']);
        $comment = $this->apiPost("/api/tickets/{$this->ticket->id}/comments", ['body' => 'حذف'], $token)->json('data');

        $this->apiDelete("/api/tickets/{$this->ticket->id}/comments/{$comment['id']}", $token)
            ->assertStatus(200);

        $this->assertSoftDeleted('ticket_comments', ['id' => $comment['id']]);
    }
}
