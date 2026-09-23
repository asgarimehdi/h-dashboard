<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketController;
use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TicketController::class);

class TicketControllerEdgeCasesTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function actingAs($user, $driver = null)
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return parent::actingAs($user, $driver);
    }

    public function test_create_requires_valid_fields(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/tickets', []);

        $response->assertStatus(422);
    }

    public function test_create_rejects_unit_outside_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $otherUnit = Unit::create(['name' => 'Other Unit']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/tickets', [
            'subject' => 'X',
            'content' => 'Y',
            'priority' => 'normal',
            'unit_id' => $otherUnit->id,
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unit not accessible.']);
    }

    public function test_update_rejects_unit_outside_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-201', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'S', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);
        $otherUnit = Unit::create(['name' => 'Other Unit']);

        // The ticket's own unit is accessible, but if it were moved to an
        // out-of-scope unit the controller would 403. Here we assert the in-scope
        // update works and the out-of-scope guard path via show.
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/tickets/{$ticket->id}", [
            'subject' => 'Updated Subject',
        ]);
        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Updated Subject']]);

        $outTicket = Ticket::create(['ticket_code' => 'T-202', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);
        $resp2 = $this->actingAs($user, 'sanctum')->putJson("/api/tickets/{$outTicket->id}", ['subject' => 'Nope']);
        $resp2->assertStatus(403);
    }

    public function test_delete_rejects_unit_outside_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        $ticket = Ticket::create(['ticket_code' => 'T-203', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_index_filters_by_status_and_priority(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        Ticket::create(['ticket_code' => 'T-204', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'A', 'content' => 'C', 'priority' => 'urgent', 'status' => 'created']);
        Ticket::create(['ticket_code' => 'T-205', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'B', 'content' => 'C', 'priority' => 'low', 'status' => 'completed']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/tickets?status=completed');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('completed', $data[0]['status']);

        $response2 = $this->actingAs($user, 'sanctum')->getJson('/api/tickets?priority=urgent');
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

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/tickets?assigned_to_me=1');
        $response->assertStatus(200);
        $data = $response->json('data');
        $ids = array_column($data, 'id');
        $this->assertContains($ticket->id, $ids);
    }

    public function test_update_with_partial_fields(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = Ticket::create(['ticket_code' => 'T-207', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'S', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/tickets/{$ticket->id}", [
            'priority' => 'urgent',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'priority' => 'urgent', 'subject' => 'S']);
    }
}
