<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketController;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
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

    public function test_user_can_show_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-002', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Show Me', 'content' => 'Body', 'priority' => 'urgent', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Show Me']]);
    }

    public function test_user_can_create_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'New Ticket',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ], $token);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tickets', ['subject' => 'New Ticket']);
    }

    public function test_user_can_create_ticket_with_medium_priority(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

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

    public function test_user_can_update_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-003', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Old', 'content' => 'Body', 'priority' => 'low', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Updated',
        ], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Updated']]);
    }

    public function test_user_can_delete_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-004', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Delete Me', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
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
}
