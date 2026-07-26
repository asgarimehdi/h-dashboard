<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class TicketApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) rand(1000000000, 9999999999);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => 1]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $unit = Unit::create(['name' => 'Test Unit']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_unauthenticated_user_cannot_access_tickets(): void
    {
        $response = $this->getJson('/api/tickets');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_tickets(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Ticket::create(['ticket_code' => 'T-001', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Test', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/tickets');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_user_can_show_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-002', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Show Me', 'content' => 'Body', 'priority' => 'urgent', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Show Me']]);
    }

    public function test_user_can_create_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/tickets', [
            'subject' => 'New Ticket',
            'content' => 'Description',
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tickets', ['subject' => 'New Ticket']);
    }

    public function test_user_can_update_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-003', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Old', 'content' => 'Body', 'priority' => 'low', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/tickets/{$ticket->id}", [
            'subject' => 'Updated',
        ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['subject' => 'Updated']]);
    }

    public function test_user_can_delete_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-004', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Delete Me', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }

    public function test_user_can_assign_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-005', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Assign', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/tickets/{$ticket->id}/assign", [
            'user_id' => $user->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'forwarded', 'current_assignee_id' => $user->id]]);
    }

    public function test_user_can_accept_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-006', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Accept', 'content' => 'Body', 'priority' => 'normal', 'status' => 'forwarded']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/tickets/{$ticket->id}/accept");

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'accepted']]);
    }

    public function test_user_can_complete_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-007', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Complete', 'content' => 'Body', 'priority' => 'normal', 'status' => 'accepted']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/tickets/{$ticket->id}/complete");

        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'completed']]);
        $this->assertNotNull($ticket->fresh()->completed_at);
    }

    public function test_cannot_complete_non_accepted_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-008', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Not Ready', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/tickets/{$ticket->id}/complete");

        $response->assertStatus(422);
    }

    public function test_user_cannot_access_inaccessible_ticket(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $otherUnit = Unit::create(['name' => 'Other']);
        $ticket = Ticket::create(['ticket_code' => 'T-009', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(403);
    }
}
