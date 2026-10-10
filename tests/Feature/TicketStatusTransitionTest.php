<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketController;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TicketController::class);

/**
 * Issue #935 — the status-transition contract, tested where it actually lives.
 *
 * Nothing in the model layer fills `accepted_at` / `completed_at`. The two
 * write endpoints do it explicitly, as one unit with the status:
 *
 *   Api\TicketController::accept()   → ['status' => 'accepted',  'accepted_at' => now()]
 *   Api\TicketController::complete() → ['status' => 'completed', 'completed_at' => now()]
 *
 * `tests/Feature/TicketWorkflowTest.php` had zero running tests for a year, so
 * the only assertions on the three `datetime` casts of the Ticket model and on
 * this transition pair were never executed. This file pins both halves, and the
 * last test documents the boundary explicitly so a future model-level
 * "improvement" cannot silently move the responsibility.
 */
class TicketStatusTransitionTest extends TestCase
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
     * @return array{user: User, unit: Unit}
     */
    private function actorWithPermissions(): array
    {
        return $this->createUserWithUnit(
            ['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'],
            'admin'
        );
    }

    public function test_accept_endpoint_writes_status_and_accepted_at_together(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->actorWithPermissions();

        $ticket = Ticket::factory()->for($user, 'user')->for($unit, 'unit')->forwarded()->create([
            'current_assignee_id' => $user->id,
            'accepted_at' => null,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $response->assertStatus(200)->assertJson(['data' => ['status' => 'accepted']]);

        $fresh = $ticket->fresh();
        $this->assertEquals('accepted', $fresh->status);
        $this->assertInstanceOf(Carbon::class, $fresh->accepted_at);
        // The pair is written together — accepting must not pre-fill completed_at.
        $this->assertNull($fresh->completed_at);
    }

    public function test_complete_endpoint_writes_status_and_completed_at_together(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->actorWithPermissions();

        $ticket = Ticket::factory()->for($user, 'user')->for($unit, 'unit')->accepted()->create([
            'current_assignee_id' => $user->id,
        ]);
        $acceptedAt = $ticket->accepted_at;

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $response = $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token);

        $response->assertStatus(200)->assertJson(['data' => ['status' => 'completed']]);

        $fresh = $ticket->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertInstanceOf(Carbon::class, $fresh->completed_at);
        // Completing must not rewrite when the ticket was accepted.
        $this->assertEquals($acceptedAt->toDateTimeString(), $fresh->accepted_at->toDateTimeString());
    }

    public function test_rejecting_an_accepted_ticket_keeps_accepted_at_and_leaves_completed_at_null(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->actorWithPermissions();

        $ticket = Ticket::factory()->for($user, 'user')->for($unit, 'unit')->created()->create([
            'current_assignee_id' => $user->id,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token);

        $ticket->update(['status' => 'rejected']);
        $fresh = $ticket->fresh();

        $this->assertEquals('rejected', $fresh->status);
        $this->assertInstanceOf(Carbon::class, $fresh->accepted_at);
        $this->assertNull($fresh->completed_at);
    }

    /**
     * The contract is endpoint-enforced, NOT model-enforced — and that is
     * deliberate, so this test states it rather than leaving it as folklore.
     *
     * `Ticket` has no observer and no cast that reacts to a status change, so a
     * direct `update(['status' => 'accepted'])` from any future write path
     * leaves `accepted_at` null. The two endpoints above are what keep the pair
     * honest. If someone later moves this into a model hook, THIS test fails
     * and forces the documentation to be updated with it.
     */
    public function test_a_bare_status_update_does_not_fill_the_timestamp(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->actorWithPermissions();

        $ticket = Ticket::factory()->for($user, 'user')->for($unit, 'unit')->forwarded()->create([
            'current_assignee_id' => $user->id,
            'accepted_at' => null,
        ]);

        $ticket->update(['status' => 'accepted']);

        $fresh = $ticket->fresh();
        $this->assertEquals('accepted', $fresh->status);
        $this->assertNull($fresh->accepted_at);
    }
}
