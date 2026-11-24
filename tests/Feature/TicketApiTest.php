<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketController;
use App\Models\Attachment;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TicketController::class);

class TicketApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Create a second user in the SAME unit as the given unit.
     * Returns the user with the 'admin' role and all ticket permissions.
     */
    protected function createSecondUserInUnit(Unit $unit): User
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'A', 'l_name' => 'B',
            't_id' => DB::table('tahsils')->first()->id,
            'e_id' => DB::table('estekhdams')->first()->id,
            's_id' => DB::table('semats')->first()->id,
            'r_id' => DB::table('radifs')->first()->id,
            'u_id' => $unit->id,
        ]);
        $user = User::factory()->create(['n_code' => $nCode]);
        $user->assignRole('admin');
        $user->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    /**
     * #1523: a ticket target must be both receiving and active. `UnitFactory`
     * sets neither flag, so the migration defaults apply (`is_active = true`,
     * `can_receive_tickets = false`) and a bare factory unit is NOT a valid
     * destination. Opt in explicitly wherever the ticket must be created.
     *
     * @param  array<string>  $permissions
     * @return array{user: User, unit: Unit}
     */
    protected function createUserWithReceivingUnit(array $permissions = [], ?string $role = null): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit($permissions, $role);
        $unit->update(['is_active' => true, 'can_receive_tickets' => true]);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_unauthenticated_user_cannot_access_tickets(): void
    {
        $response = $this->getJson('/api/tickets');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_tickets(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        Ticket::create(['ticket_code' => 'T-001', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Test', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet('/api/tickets', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    /**
     * #1189: the list payload is the pre-decision shape MINUS `content`; every
     * other field (and every `whenLoaded` block) stays exactly as it was.
     */
    public function test_index_does_not_expose_ticket_content(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        Ticket::create(['ticket_code' => 'T-LIST-1', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'First', 'content' => 'First body', 'priority' => 'normal', 'status' => 'created']);
        Ticket::create(['ticket_code' => 'T-LIST-2', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Second', 'content' => 'Second body', 'priority' => 'urgent', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet('/api/tickets', $token);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);

        foreach ($data as $item) {
            $this->assertArrayNotHasKey('content', $item);
            $this->assertArrayHasKey('subject', $item);
        }

        $keys = array_keys($data[0]);
        sort($keys);
        $expected = [
            'accepted_at', 'assignee', 'completed_at', 'created_at', 'current_assignee_id',
            'deadline', 'id', 'priority', 'status', 'status_name', 'subject', 'ticket_code',
            'unit', 'unit_id', 'updated_at', 'user', 'user_id',
        ];
        sort($expected);
        $this->assertSame($expected, $keys, 'The list shape must stay unchanged apart from dropping content.');
    }

    /**
     * #1751: the page query must select exactly the allowlist and must never
     * pull the TEXT `content` column. The anti-vacuous `assertCount(1, …)` is
     * load-bearing: without it the test would pass on an empty query log.
     */
    public function test_index_page_query_selects_the_allowlist_without_content(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        Ticket::create([
            'ticket_code' => 'T-HEAVY', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'Heavy', 'content' => str_repeat('م', 20000),
            'priority' => 'normal', 'status' => 'created',
        ]);

        $token = $this->createApiToken($user, ['tickets:read']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->apiGet('/api/tickets', $token)->assertStatus(200);
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $pageQueries = $log->filter(fn (string $sql): bool => str_starts_with($sql, 'select ')
            && str_contains($sql, ' from "tickets"')
            && ! str_contains($sql, 'count(*)'));

        $this->assertCount(1, $pageQueries, 'exactly one tickets page query expected');

        $sql = (string) $pageQueries->first();

        $this->assertStringNotContainsString('"content"', $sql);

        preg_match('/^select (.+?) from "tickets"/s', $sql, $m);
        $columns = array_map(fn (string $c): string => trim($c, ' "'), explode(',', $m[1]));
        sort($columns);

        $expected = [
            'id', 'ticket_code', 'subject', 'priority', 'status', 'user_id', 'unit_id',
            'current_assignee_id', 'deadline', 'accepted_at', 'completed_at', 'created_at', 'updated_at',
        ];
        sort($expected);
        $this->assertSame($expected, $columns, 'the list query must select exactly the allowlist.');
    }

    /**
     * #1751: an FK column dropped from the allowlist nulls its relation
     * silently, so assert the relation VALUES, not just their keys.
     */
    public function test_index_relations_still_resolve_after_explicit_select(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $assignee = $this->createSecondUserInUnit($unit);

        $ticket = Ticket::create([
            'ticket_code' => 'T-REL', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'Relations', 'content' => '', 'priority' => 'normal', 'status' => 'created',
        ]);
        $ticket->update(['current_assignee_id' => $assignee->id]);

        $token = $this->createApiToken($user, ['tickets:read']);
        $this->apiGet('/api/tickets', $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.unit.id', $unit->id)
            ->assertJsonPath('data.0.user.n_code', $user->n_code)
            ->assertJsonPath('data.0.assignee.n_code', $assignee->n_code);
    }

    public function test_user_can_show_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-002', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Show Me', 'content' => 'Body', 'priority' => 'urgent', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet("/api/tickets/{$ticket->id}", $token);

        // #1189: detail responses keep `content` with its real value.
        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Show Me', 'content' => 'Body']]);
    }

    public function test_user_can_create_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithReceivingUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'New Ticket',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ], $token);

        // #1189: the created ticket is a detail response, so `content` is present.
        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.content', 'Description');

        $this->assertDatabaseHas('tickets', ['subject' => 'New Ticket']);
    }

    public function test_user_can_create_ticket_with_medium_priority(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithReceivingUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Urgent Priority',
            'content' => 'Description',
            'priority' => 'urgent',
            'unit_id' => $unit->id,
        ], $token);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tickets', ['subject' => 'Urgent Priority', 'priority' => 'urgent']);
    }

    /**
     * #1523: `POST /api/tickets` only ran `exists:units,id`, so a ticket could
     * be created in a unit that cannot receive tickets. The destination must
     * now pass `App\Rules\TicketTargetUnit` like the three web paths do.
     */
    public function test_cannot_create_ticket_for_a_unit_that_cannot_receive_tickets(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $unit->update(['is_active' => true, 'can_receive_tickets' => false]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Blocked',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('unit_id')
            ->assertJsonPath('errors.unit_id.0', 'این واحد امکان دریافت تیکت ندارد.');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Blocked']);
    }

    /**
     * #1523: the narrow half — an inactive unit is reachable through the
     * caller's own root/`user_units` seed, so the scope never filters it out.
     */
    public function test_cannot_create_ticket_for_an_inactive_root_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $unit->update(['is_active' => false, 'can_receive_tickets' => true]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Blocked',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('unit_id')
            ->assertJsonPath('errors.unit_id.0', 'این واحد غیرفعال است.');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Blocked']);
    }

    /**
     * #1523: pins the rule-before-scope order. An inactive child of an active
     * parent is filtered OUT of the scope, so scope-first would answer 403.
     * The eligibility rule runs in `validate()` and answers 422 instead.
     */
    public function test_cannot_create_ticket_for_an_inactive_child_of_an_active_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithReceivingUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $child = Unit::create(['name' => 'Inactive Child', 'parent_id' => $unit->id]);
        $child->update(['is_active' => false, 'can_receive_tickets' => true]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Blocked',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => $child->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('unit_id')
            ->assertJsonPath('errors.unit_id.0', 'این واحد غیرفعال است.');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Blocked']);
    }

    /**
     * #1523: eligibility is not accessibility — a valid destination outside
     * the caller's scope keeps the existing 403 contract.
     */
    public function test_cannot_create_ticket_for_an_eligible_unit_outside_the_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $otherUnit = Unit::create(['name' => 'Elsewhere']);
        $otherUnit->update(['is_active' => true, 'can_receive_tickets' => true]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Out of Scope',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => $otherUnit->id,
        ], $token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unit not accessible.']);

        $this->assertDatabaseMissing('tickets', ['subject' => 'Out of Scope']);
    }

    /**
     * #1523: `exists:units,id` was dropped because the rule already reports a
     * missing unit. Keeping both would return two errors — one English and
     * one Persian — for the same input.
     */
    public function test_create_ticket_with_a_missing_unit_reports_only_the_persian_message(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Missing Unit',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => 999999,
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('unit_id')
            ->assertJsonPath('errors.unit_id.0', 'واحد انتخابی معتبر نیست.');

        $this->assertCount(1, $response->json('errors.unit_id'));
        $this->assertDatabaseMissing('tickets', ['subject' => 'Missing Unit']);
    }

    /**
     * #1523: without `integer`, `unit_id[]` slipped past `exists` and hit the
     * typed `assertAccessibleUnit(int)` — a TypeError/500. It is a 422.
     */
    public function test_create_ticket_with_an_array_unit_id_is_a_validation_error(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithReceivingUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Array Unit',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => [$unit->id],
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Array Unit']);
    }

    /**
     * #1523: `update()` never listed `unit_id` in its rules, and `validate()`
     * returns only ruled keys — so retargeting is impossible. Pin it.
     */
    public function test_update_cannot_retarget_the_ticket_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithReceivingUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-RETARGET', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Original', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);
        $otherUnit = Unit::create(['name' => 'Other Unit']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Updated',
            'unit_id' => $otherUnit->id,
        ], $token);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'subject' => 'Updated',
            'unit_id' => $unit->id,
        ]);
    }

    public function test_user_can_update_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-003', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Old', 'content' => 'Body', 'priority' => 'low', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Updated',
        ], $token);

        // #1189: update is a detail response, so `content` is present.
        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Updated', 'content' => 'Body']]);
    }

    /**
     * #1633: a completed ticket is immutable — its subject/content/priority/
     * deadline cannot be rewritten after the work is done and audited.
     */
    public function test_update_completed_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $completedAt = now()->subDay()->startOfSecond();
        $ticket = $this->createAssignedTicket($user, $unit, 'completed', ['completed_at' => $completedAt]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Rewritten',
            'content' => 'Rewritten body',
            'priority' => 'urgent',
            'deadline' => now()->addWeek()->toDateString(),
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be edited.']);

        $fresh = $ticket->fresh();
        $this->assertSame('Race', $fresh->subject);
        $this->assertSame('Body', $fresh->content);
        $this->assertSame('normal', $fresh->priority);
        $this->assertNull($fresh->deadline);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($completedAt->toDateTimeString(), $fresh->completed_at->toDateTimeString());
    }

    public function test_update_rejected_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'rejected');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Rewritten',
            'content' => 'Rewritten body',
            'priority' => 'urgent',
            'deadline' => now()->addWeek()->toDateString(),
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be edited.']);

        $fresh = $ticket->fresh();
        $this->assertSame('Race', $fresh->subject);
        $this->assertSame('normal', $fresh->priority);
        $this->assertSame('rejected', $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_update_archived_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $at = now()->subDay()->startOfSecond();
        $ticket = $this->createAssignedTicket($user, $unit, 'completed', [
            'completed_at' => $at,
            'archived_at' => $at,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Rewritten',
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be edited.']);

        $fresh = $ticket->fresh();
        $this->assertSame('Race', $fresh->subject);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($at->toDateTimeString(), $fresh->completed_at->toDateTimeString());
        $this->assertSame($at->toDateTimeString(), $fresh->archived_at->toDateTimeString());
    }

    public function test_update_open_tickets_remains_editable(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $acceptedAt = now()->subDay()->startOfSecond();

        foreach (['created', 'forwarded', 'accepted'] as $status) {
            $ticket = $this->createAssignedTicket($user, $unit, $status, [
                'accepted_at' => $status === 'accepted' ? $acceptedAt : null,
            ]);

            $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
            $response = $this->apiPut("/api/tickets/{$ticket->id}", [
                'subject' => "Updated {$status}",
            ], $token);

            $response->assertStatus(200)
                ->assertJson(['data' => ['subject' => "Updated {$status}", 'status' => $status]]);

            $this->assertDatabaseHas('tickets', [
                'id' => $ticket->id,
                'subject' => "Updated {$status}",
                'status' => $status,
            ]);
        }
    }

    public function test_update_loses_race_when_ticket_closed_under_lock(): void
    {
        // A concurrent complete() lands between the controller's read and the
        // write. A pre-transaction status check would miss it; the guard must
        // run on the locked row.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'completed',
            'current_assignee_id' => $user->id,
            'archived_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Hijacked',
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be edited.']);

        $fresh = $ticket->fresh();
        $this->assertSame('Race', $fresh->subject);
        $this->assertSame('created', $fresh->status);
    }

    public function test_update_loses_race_when_row_vanished_under_lock(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow(null);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Hijacked',
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Ticket no longer exists.']);

        $fresh = $ticket->fresh();
        $this->assertSame('Race', $fresh->subject);
        $this->assertSame('created', $fresh->status);
    }

    public function test_user_can_delete_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-004', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Delete Me', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);

        // #1683: an allowed delete leaves an audit row behind. activity_logs
        // has no FK to subject_id, so the row outlives the ticket (and its
        // cascaded comments/attachments/task_activities).
        $log = DB::table('activity_logs')
            ->where('type', 'deleted')
            ->where('subject_type', Ticket::class)
            ->where('subject_id', $ticket->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, (int) $log->user_id);

        $old = json_decode((string) $log->old_values, true);
        $this->assertSame('created', $old['status']);
        $this->assertSame($unit->id, $old['unit_id']);
    }

    // =====================================================================
    // #1683 — a closed/archived ticket is not deletable (terminal-state guard)
    // =====================================================================

    public function test_delete_completed_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create([
            'ticket_code' => 'T-DEL-COMPLETED',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Done',
            'content' => 'Body',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'History that must survive.',
        ]);

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => 'attachments/report.pdf',
            'file_name' => 'report.pdf',
            'file_size' => 9,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be deleted.']);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('ticket_comments', ['id' => $comment->id]);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('activity_logs', ['type' => 'deleted', 'subject_id' => $ticket->id]);
    }

    public function test_delete_rejected_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'rejected');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be deleted.']);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_delete_archived_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        // #929: archiving is one-way. The status may still read `created`
        // while `archived_at` is stamped — the archived branch alone must block.
        $ticket = $this->createAssignedTicket($user, $unit, 'created', ['archived_at' => now()]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be deleted.']);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_delete_loses_race_when_ticket_closed_under_lock(): void
    {
        // A concurrent complete() lands between the controller's read and the
        // delete. A pre-transaction status check would miss it; the guard must
        // run on the locked row.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'completed',
            'unit_id' => $unit->id,
            'archived_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be deleted.']);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_delete_loses_race_when_row_vanished_under_lock(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow(null);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Ticket no longer exists.']);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_delete_without_manage_unit_tickets_is_forbidden(): void
    {
        // #1683 regression pin: the route gate stays `permission:manage_unit_tickets`.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $token = $this->createApiToken($user, ['tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(403);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_delete_ticket_outside_unit_scope_is_forbidden(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $otherUnit = Unit::create(['name' => 'Other']);
        $ticket = Ticket::create(['ticket_code' => 'T-DEL-SCOPE', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unit not accessible.']);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_user_can_assign_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-005', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Assign', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $user->id,
        ], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'forwarded', 'current_assignee_id' => $user->id]]);
    }

    public function test_user_can_accept_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-006', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Accept', 'content' => 'Body', 'priority' => 'normal', 'status' => 'forwarded', 'current_assignee_id' => $user->id]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'accepted']]);
    }

    public function test_non_assignee_cannot_accept_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $user2 = $this->createSecondUserInUnit($unit);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $ticket = Ticket::create(['ticket_code' => 'T-0529', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Not Yours', 'content' => 'Body', 'priority' => 'normal', 'status' => 'forwarded', 'current_assignee_id' => $user->id]);

        $token2 = $this->createApiToken($user2, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token2);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can accept this ticket.']);

        $this->assertEquals('forwarded', $ticket->fresh()->status);
    }

    public function test_user_can_complete_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-007', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Complete', 'content' => 'Body', 'priority' => 'normal', 'status' => 'accepted', 'current_assignee_id' => $user->id]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'completed']]);
        $this->assertNotNull($ticket->fresh()->completed_at);
    }

    public function test_non_assignee_cannot_complete_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $user2 = $this->createSecondUserInUnit($unit);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $ticket = Ticket::create(['ticket_code' => 'T-0530', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Not Yours', 'content' => 'Body', 'priority' => 'normal', 'status' => 'accepted', 'current_assignee_id' => $user->id]);

        $token2 = $this->createApiToken($user2, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token2);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can complete this ticket.']);

        $this->assertEquals('accepted', $ticket->fresh()->status);
    }

    public function test_cannot_complete_non_accepted_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-008', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Not Ready', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created', 'current_assignee_id' => $user->id]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token);

        $response->assertStatus(422);
    }

    public function test_user_cannot_access_inaccessible_ticket(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $otherUnit = Unit::create(['name' => 'Other']);
        $ticket = Ticket::create(['ticket_code' => 'T-009', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(403);
    }

    public function test_cannot_assign_ticket_to_user_outside_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-010', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Scoped', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        ['user' => $otherUser] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $otherUser->id,
        ], $token);

        // The other user is in a different unit — rejected with 403
        $response->assertStatus(403);

        $this->assertNull($ticket->fresh()->current_assignee_id);
    }

    public function test_can_assign_ticket_to_user_in_same_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-011', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Scoped', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $user2 = $this->createSecondUserInUnit($unit);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $user2->id,
        ], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'forwarded', 'current_assignee_id' => $user2->id]]);
    }

    public function test_index_rejects_invalid_status_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet('/api/tickets?status=nonexistent', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_index_rejects_invalid_priority_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet('/api/tickets?priority=critical', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('priority');
    }

    public function test_index_accepts_valid_status_values(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        Ticket::create(['ticket_code' => 'T-VAL', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'V', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read']);
        foreach (['created', 'forwarded', 'accepted', 'completed', 'rejected'] as $status) {
            $response = $this->apiGet("/api/tickets?status={$status}", $token);
            $response->assertStatus(200);
        }
    }

    public function test_ticket_resource_exposes_expected_fields(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-RES', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Resource Test', 'content' => 'Body', 'priority' => 'urgent', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id', 'ticket_code', 'subject', 'content', 'priority', 'status', 'status_name',
                    'user_id', 'unit_id', 'current_assignee_id', 'deadline', 'accepted_at', 'completed_at',
                    'created_at', 'updated_at', 'unit', 'user',
                ],
            ]);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('deleted_at', $data);
    }

    /**
     * Issue #1276 — attachments left the public disk, so the app can no longer
     * build `baseUrl + '/storage/' + file_path`. The detail payload carries an
     * absolute API download URL instead; `file_path` stays for compatibility
     * (deprecated, not removed).
     */
    public function test_api_detail_exposes_the_attachment_download_url(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-ATT', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Attachment', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => 'attachments/report.pdf',
            'file_name' => 'report.pdf',
            'file_size' => 9,
        ]);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet("/api/tickets/{$ticket->id}", $token);

        $url = route('api.tickets.attachments.download', [
            'ticket' => $ticket->id,
            'attachment' => $attachment->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.attachments.0.id', $attachment->id)
            ->assertJsonPath('data.attachments.0.url', $url)
            ->assertJsonPath('data.attachments.0.file_path', 'attachments/report.pdf');

        // The app's URL must be the token-authenticated API route, never the
        // web route (which `unit_context` redirects for a sessionless token).
        $this->assertStringContainsString('/api/tickets/', $url);
    }

    public function test_cannot_accept_completed_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $acceptedAt = now()->subDay()->startOfSecond();
        $ticket = Ticket::create(['ticket_code' => 'T-ACC-COMPLETED', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Done', 'content' => 'Body', 'priority' => 'normal', 'status' => 'completed', 'current_assignee_id' => $user->id, 'accepted_at' => $acceptedAt, 'completed_at' => now()]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Only created or forwarded tickets can be accepted.']);

        $fresh = $ticket->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($acceptedAt->toDateTimeString(), $fresh->accepted_at->toDateTimeString());
    }

    public function test_cannot_accept_rejected_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-ACC-REJECTED', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Rejected', 'content' => 'Body', 'priority' => 'normal', 'status' => 'rejected', 'current_assignee_id' => $user->id, 'accepted_at' => null]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Only created or forwarded tickets can be accepted.']);

        $fresh = $ticket->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertNull($fresh->accepted_at);
    }

    public function test_cannot_reaccept_already_accepted_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $acceptedAt = now()->subDay()->startOfSecond();
        $ticket = Ticket::create(['ticket_code' => 'T-ACC-TWICE', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Already Accepted', 'content' => 'Body', 'priority' => 'normal', 'status' => 'accepted', 'current_assignee_id' => $user->id, 'accepted_at' => $acceptedAt]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Only created or forwarded tickets can be accepted.']);

        $fresh = $ticket->fresh();
        $this->assertSame('accepted', $fresh->status);
        // The reported harm of the bug: re-accepting silently re-stamps accepted_at.
        $this->assertSame($acceptedAt->toDateTimeString(), $fresh->accepted_at->toDateTimeString());
    }

    public function test_non_assignee_cannot_accept_completed_ticket_even_though_status_is_invalid(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $user2 = $this->createSecondUserInUnit($unit);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $ticket = Ticket::create(['ticket_code' => 'T-ACC-ORDER', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Order', 'content' => 'Body', 'priority' => 'normal', 'status' => 'completed', 'current_assignee_id' => $user->id, 'accepted_at' => now(), 'completed_at' => now()]);

        $token2 = $this->createApiToken($user2, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token2);

        // The assignee check must win over the status guard: 403, not 422.
        $response->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can accept this ticket.']);

        $this->assertSame('completed', $ticket->fresh()->status);
    }

    // =====================================================================
    // #1608 — transitions are atomic; closed tickets stay closed
    // =====================================================================

    /**
     * Create a ticket already assigned to $user.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createAssignedTicket(User $user, Unit $unit, string $status = 'created', array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_code' => 'T-'.fake()->unique()->numerify('######'),
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Race',
            'content' => 'Body',
            'priority' => 'normal',
            'status' => $status,
            'current_assignee_id' => $user->id,
        ], $overrides));
    }

    /**
     * Make the controller's `FOR UPDATE` probe observe a stale snapshot.
     *
     * Same mechanism as TicketsInboxLivewireTest (#1564 review §4): `DB::spy()`
     * swaps the container `db` binding, so Eloquent resolves the mock too and
     * the transaction callback never runs. A DatabaseManager subclass that
     * delegates `connection()` to the real manager avoids that — a fresh
     * manager would open a second PDO outside RefreshDatabase's transaction
     * and see no rows at all.
     *
     * @param  object|null  $row  the row the lock reports, or null for "gone"
     */
    protected function fakeLockedTicketRow(?object $row): void
    {
        /** @var DatabaseManager $inner */
        $inner = DB::getFacadeRoot();

        DB::swap(new class(app(), app('db.factory'), $inner, $row) extends DatabaseManager
        {
            public function __construct($app, $factory, private DatabaseManager $inner, private ?object $row)
            {
                parent::__construct($app, $factory);
            }

            public function connection($name = null)
            {
                return $this->inner->connection($name);
            }

            public function select($query, $bindings = [], $useReadPdo = true)
            {
                if (str_contains($query, 'FOR UPDATE')) {
                    return $this->row === null ? [] : [$this->row];
                }

                return $this->inner->connection()->select($query, $bindings, $useReadPdo);
            }
        });
    }

    public function test_accept_loses_race_when_status_changed_under_lock(): void
    {
        // The row moved from `created` to `accepted` between the controller's
        // read and its FOR UPDATE probe. The loser must not re-stamp accepted_at.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'accepted',
            'current_assignee_id' => $user->id,
            'archived_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Only created or forwarded tickets can be accepted.']);

        $fresh = $ticket->fresh();
        $this->assertSame('created', $fresh->status);
        $this->assertNull($fresh->accepted_at);
    }

    public function test_accept_loses_race_when_assignee_changed_under_lock(): void
    {
        // assign()/forward() move current_assignee_id without changing status,
        // so comparing status alone would let the earlier reader seize a ticket
        // now assigned to a teammate (#529, one transaction deeper).
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $ticket = $this->createAssignedTicket($user, $unit, 'forwarded');

        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'forwarded',
            'current_assignee_id' => $teammate->id,
            'archived_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can accept this ticket.']);

        $fresh = $ticket->fresh();
        $this->assertSame('forwarded', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
        $this->assertNull($fresh->accepted_at);
    }

    public function test_accept_loses_race_when_row_vanished_under_lock(): void
    {
        // A DELETE between route-model binding and the lock leaves nothing to
        // lock. That is a loss (422), never a 500 or a success.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow(null);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Only created or forwarded tickets can be accepted.']);

        $fresh = $ticket->fresh();
        $this->assertSame('created', $fresh->status);
        $this->assertNull($fresh->accepted_at);
    }

    public function test_complete_loses_race_when_status_changed_under_lock(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'accepted', ['accepted_at' => now()]);

        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'forwarded',
            'current_assignee_id' => $user->id,
            'archived_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Ticket must be accepted before completing.']);

        $fresh = $ticket->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_complete_loses_race_when_assignee_changed_under_lock(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $ticket = $this->createAssignedTicket($user, $unit, 'accepted', ['accepted_at' => now()]);

        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'accepted',
            'current_assignee_id' => $teammate->id,
            'archived_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can complete this ticket.']);

        $fresh = $ticket->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
        $this->assertNull($fresh->completed_at);
    }

    public function test_assign_completed_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $completedAt = now()->subDay()->startOfSecond();
        $ticket = $this->createAssignedTicket($user, $unit, 'completed', ['completed_at' => $completedAt]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $teammate->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be reassigned.']);

        $fresh = $ticket->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
        $this->assertSame($completedAt->toDateTimeString(), $fresh->completed_at->toDateTimeString());
    }

    public function test_assign_rejected_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $ticket = $this->createAssignedTicket($user, $unit, 'rejected');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $teammate->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be reassigned.']);

        $fresh = $ticket->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
    }

    public function test_assign_archived_ticket_returns_422(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $archivedAt = now()->subDay()->startOfSecond();
        $ticket = $this->createAssignedTicket($user, $unit, 'completed', [
            'completed_at' => $archivedAt,
            'archived_at' => $archivedAt,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $teammate->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be reassigned.']);

        $fresh = $ticket->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
        $this->assertSame($archivedAt->toDateTimeString(), $fresh->archived_at->toDateTimeString());
    }

    public function test_assign_rejects_ticket_closed_under_lock(): void
    {
        // A concurrent complete() lands between the controller's read and the
        // update. A pre-transaction status check would miss it; the guard must
        // run on the locked row.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'completed',
            'current_assignee_id' => $user->id,
            'archived_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $teammate->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Closed tickets cannot be reassigned.']);

        $fresh = $ticket->fresh();
        $this->assertSame('created', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
    }

    public function test_assign_loses_race_when_row_vanished_under_lock(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $this->fakeLockedTicketRow(null);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
            'assignee_id' => $teammate->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Ticket no longer exists.']);

        $fresh = $ticket->fresh();
        $this->assertSame('created', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
    }

    public function test_accept_from_created_status_still_works(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->createAssignedTicket($user, $unit, 'created');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'accepted']]);

        $fresh = $ticket->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame($user->id, $fresh->current_assignee_id);
        $this->assertNotNull($fresh->accepted_at);
    }

    public function test_assign_is_allowed_from_forwarded_and_accepted_statuses(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $teammate = $this->createSecondUserInUnit($unit);
        $acceptedAt = now()->subDay()->startOfSecond();

        foreach (['forwarded', 'accepted'] as $status) {
            $ticket = $this->createAssignedTicket($user, $unit, $status, [
                'ticket_code' => 'T-ASSIGN-'.strtoupper($status),
                'accepted_at' => $status === 'accepted' ? $acceptedAt : null,
            ]);

            $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
            $response = $this->apiPost("/api/tickets/{$ticket->id}/assign", [
                'assignee_id' => $teammate->id,
            ], $token);

            $response->assertStatus(200)
                ->assertJson(['data' => ['status' => 'forwarded', 'current_assignee_id' => $teammate->id]]);

            $fresh = $ticket->fresh();
            $this->assertSame('forwarded', $fresh->status);
            $this->assertSame($teammate->id, $fresh->current_assignee_id);
            $this->assertSame(
                $status === 'accepted' ? $acceptedAt->toDateTimeString() : null,
                $fresh->accepted_at?->toDateTimeString()
            );
        }
    }

    /**
     * #1652: `content` had no upper bound on either API write path, so the
     * lowest role could store multi-MB bodies. The cap is 20000 characters —
     * Laravel counts characters (`mb_strlen`), not bytes.
     */
    public function test_store_rejects_content_above_max_length(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithReceivingUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Too Long',
            'content' => str_repeat('ا', 20001),
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('content');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Too Long']);
    }

    public function test_update_rejects_content_above_max_length_and_keeps_old_body(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-1652', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Old', 'content' => 'Body', 'priority' => 'low', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'content' => str_repeat('ا', 20001),
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('content');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'content' => 'Body']);
    }

    public function test_store_accepts_content_at_exactly_max_length(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithReceivingUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $content = str_repeat('ا', 20000);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'Boundary',
            'content' => $content,
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ], $token);

        // Proves `max` counts characters, not bytes: 20000 Persian letters
        // are ~40KB in UTF-8.
        $response->assertStatus(201)
            ->assertJsonPath('data.content', $content);

        $this->assertDatabaseHas('tickets', ['subject' => 'Boundary']);
    }
}
