<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketController;
use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TicketController::class);

class TicketControllerEdgeCasesTest extends TestCase
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

    public function test_create_requires_valid_fields(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [], $token);

        $response->assertStatus(422);
    }

    public function test_create_rejects_unit_outside_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $otherUnit = Unit::create(['name' => 'Other Unit']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost('/api/tickets', [
            'subject' => 'X',
            'content' => 'Y',
            'priority' => 'normal',
            'unit_id' => $otherUnit->id,
        ], $token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unit not accessible.']);
    }

    public function test_update_rejects_unit_outside_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-201', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'S', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);
        $otherUnit = Unit::create(['name' => 'Other Unit']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);

        // The ticket's own unit is accessible, but if it were moved to an
        // out-of-scope unit the controller would 403. Here we assert the in-scope
        // update works and the out-of-scope guard path via show.
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'subject' => 'Updated Subject',
        ], $token);
        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Updated Subject']]);

        $outTicket = Ticket::create(['ticket_code' => 'T-202', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);
        $resp2 = $this->apiPut("/api/tickets/{$outTicket->id}", ['subject' => 'Nope'], $token);
        $resp2->assertStatus(403);
    }

    public function test_delete_rejects_unit_outside_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        $ticket = Ticket::create(['ticket_code' => 'T-203', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(403);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_index_filters_by_status_and_priority(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        Ticket::create(['ticket_code' => 'T-204', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'A', 'content' => 'C', 'priority' => 'urgent', 'status' => 'created']);
        Ticket::create(['ticket_code' => 'T-205', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'B', 'content' => 'C', 'priority' => 'low', 'status' => 'completed']);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet('/api/tickets?status=completed', $token);
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('completed', $data[0]['status']);

        $response2 = $this->apiGet('/api/tickets?priority=urgent', $token);
        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals('urgent', $data2[0]['priority']);
    }

    public function test_index_assigned_to_me_filter(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-206', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Mine', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);
        $ticket->update(['current_assignee_id' => $user->id]);

        $token = $this->createApiToken($user, ['tickets:read']);
        $response = $this->apiGet('/api/tickets?assigned_to_me=1', $token);
        $response->assertStatus(200);
        $data = $response->json('data');
        $ids = array_column($data, 'id');
        $this->assertContains($ticket->id, $ids);
    }

    public function test_update_with_partial_fields(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-207', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'S', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPut("/api/tickets/{$ticket->id}", [
            'priority' => 'urgent',
        ], $token);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'priority' => 'urgent', 'subject' => 'S']);
    }
}
