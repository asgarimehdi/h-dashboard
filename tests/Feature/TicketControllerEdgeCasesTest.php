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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketControllerEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions(Permission::all());

        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => 1]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->assignRole('admin');
        $user->givePermissionTo(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $unit = Unit::create(['name' => 'Test Unit']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => User::find($user->id), 'unit' => $unit];
    }

    public function actingAs($user, $driver = null)
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return parent::actingAs($user, $driver);
    }

    public function test_create_requires_valid_fields(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/tickets', []);

        $response->assertStatus(422);
    }

    public function test_create_rejects_unit_outside_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        $ticket = Ticket::create(['ticket_code' => 'T-203', 'user_id' => $user->id, 'unit_id' => $otherUnit->id, 'subject' => 'Hidden', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_index_filters_by_status_and_priority(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $ticket = Ticket::create(['ticket_code' => 'T-207', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'S', 'content' => 'C', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/tickets/{$ticket->id}", [
            'priority' => 'high',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'priority' => 'high', 'subject' => 'S']);
    }
}
