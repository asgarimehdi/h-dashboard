<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1909: assignment notifies the assignee, acceptance/completion notifies the
 * ticket creator. Every assertion is scoped by notification `type`, never by a
 * global row count, so unrelated notifications cannot mask a regression.
 */
class TicketTransitionNotificationTest extends TestCase
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
     * A plain user attached to $unit — `createUserWithUnit()` creates its own
     * unit, so a second party on a *specific* unit needs this.
     */
    private function teammateOnUnit(Unit $unit): User
    {
        $person = Person::factory()->create(['u_id' => $unit->id]);
        $user = User::factory()->create(['n_code' => $person->n_code]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTicket(Unit $unit, ?User $creator, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_code' => 'T'.fake()->unique()->numerify('######'),
            'user_id' => $creator?->id,
            'unit_id' => $unit->id,
            'subject' => 'موضوع اعلان',
            'content' => 'محتوای اعلان',
            'priority' => 'normal',
            'status' => 'created',
        ], $overrides));
    }

    private function notificationsOfType(string $type): int
    {
        return DB::table('notifications')->where('type', $type)->count();
    }

    private function notificationsOfTypeFor(string $type, int $userId): int
    {
        return DB::table('notifications')
            ->where('type', $type)
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * The same `FOR UPDATE` probe fake used by `TicketApiTest`, so a rolled-back
     * accept can be exercised without a second connection.
     */
    private function fakeLockedTicketRow(?object $row): void
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

    // =====================================================================
    // assign (API) → assignee
    // =====================================================================

    public function test_api_assign_notifies_the_assignee_once(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $assignee = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $actor);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/assign", ['assignee_id' => $assignee->id], $token)
            ->assertStatus(200);

        $this->assertSame(1, $this->notificationsOfTypeFor('ticket_assigned', $assignee->id));
        $this->assertSame(1, $this->notificationsOfType('ticket_assigned'));

        $row = DB::table('notifications')
            ->where('type', 'ticket_assigned')
            ->where('user_id', $assignee->id)
            ->first();

        $this->assertSame('تیکت به شما ارجاع شد', $row->title);
        $this->assertStringContainsString($ticket->ticket_code, (string) $row->body);
        $this->assertSame(['ticket_id' => $ticket->id], json_decode((string) $row->data, true));
    }

    public function test_api_assign_to_self_sends_no_notification(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->makeTicket($unit, $actor);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/assign", ['assignee_id' => $actor->id], $token)
            ->assertStatus(200);

        $this->assertSame(0, $this->notificationsOfType('ticket_assigned'));
    }

    public function test_api_assign_on_closed_ticket_sends_no_notification(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $assignee = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $actor, ['status' => 'completed']);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/assign", ['assignee_id' => $assignee->id], $token)
            ->assertStatus(422);

        $this->assertSame(0, $this->notificationsOfType('ticket_assigned'));
    }

    // =====================================================================
    // accept (API + Livewire) → creator
    // =====================================================================

    public function test_api_accept_notifies_the_creator_once(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $creator = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $creator, [
            'status' => 'forwarded',
            'current_assignee_id' => $actor->id,
        ]);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token)
            ->assertStatus(200);

        $this->assertSame(1, $this->notificationsOfTypeFor('ticket_accepted', $creator->id));
        $this->assertSame(1, $this->notificationsOfType('ticket_accepted'));

        $row = DB::table('notifications')
            ->where('type', 'ticket_accepted')
            ->where('user_id', $creator->id)
            ->first();

        $this->assertSame('تیکت شما پذیرفته شد', $row->title);
        $this->assertSame(['ticket_id' => $ticket->id], json_decode((string) $row->data, true));
    }

    public function test_api_accept_by_creator_sends_no_notification(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->makeTicket($unit, $actor, [
            'status' => 'forwarded',
            'current_assignee_id' => $actor->id,
        ]);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token)
            ->assertStatus(200);

        $this->assertSame(0, $this->notificationsOfType('ticket_accepted'));
    }

    public function test_livewire_accept_notifies_the_creator_once(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['view_assigned_tickets', 'create_ticket']);
        $this->actingAs($actor);

        $creator = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $creator, [
            'status' => 'created',
            'current_assignee_id' => $actor->id,
        ]);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $ticket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'تیکت پذیرفته شد';
            });

        $this->assertSame(1, $this->notificationsOfTypeFor('ticket_accepted', $creator->id));
        $this->assertSame(1, $this->notificationsOfType('ticket_accepted'));
    }

    public function test_failed_accept_creates_no_notification(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $creator = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $creator, [
            'status' => 'forwarded',
            'current_assignee_id' => $actor->id,
        ]);

        // The locked row says `accepted`: the transition is rejected, so the
        // service returns before registering its afterCommit callback.
        $this->fakeLockedTicketRow((object) [
            'id' => $ticket->id,
            'status' => 'accepted',
            'current_assignee_id' => $actor->id,
        ]);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token)
            ->assertStatus(422);

        $this->assertSame(0, $this->notificationsOfType('ticket_accepted'));
        $this->assertSame('forwarded', $ticket->fresh()->status);
    }

    // =====================================================================
    // complete (API + Livewire) → creator
    // =====================================================================

    public function test_api_complete_notifies_the_creator_once(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $creator = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $creator, [
            'status' => 'accepted',
            'current_assignee_id' => $actor->id,
        ]);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token)
            ->assertStatus(200);

        $this->assertSame(1, $this->notificationsOfTypeFor('ticket_completed', $creator->id));
        $this->assertSame(1, $this->notificationsOfType('ticket_completed'));

        $row = DB::table('notifications')
            ->where('type', 'ticket_completed')
            ->where('user_id', $creator->id)
            ->first();

        $this->assertSame('تیکت شما مختومه شد', $row->title);
        $this->assertSame(['ticket_id' => $ticket->id], json_decode((string) $row->data, true));
    }

    public function test_api_complete_by_creator_sends_no_notification(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $ticket = $this->makeTicket($unit, $actor, [
            'status' => 'accepted',
            'current_assignee_id' => $actor->id,
        ]);

        $token = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/complete", [], $token)
            ->assertStatus(200);

        $this->assertSame(0, $this->notificationsOfType('ticket_completed'));
    }

    public function test_livewire_complete_notifies_the_creator_once(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($actor);

        $creator = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $creator, [
            'status' => 'accepted',
            'current_assignee_id' => $actor->id,
        ]);

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('completionNote', 'گزارش نهایی تست')
            ->call('submitAction')
            ->assertDispatched('swal');

        $this->assertSame(1, $this->notificationsOfTypeFor('ticket_completed', $creator->id));
        $this->assertSame(1, $this->notificationsOfType('ticket_completed'));
    }

    // =====================================================================
    // no double notification on the forward path
    // =====================================================================

    public function test_livewire_forward_does_not_send_ticket_assigned(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($actor);

        $target = Unit::create([
            'name' => 'واحد مقصد اعلان',
            'is_active' => true,
            'can_receive_tickets' => true,
        ]);
        $recipient = $this->teammateOnUnit($target);

        $ticket = $this->makeTicket($unit, $actor);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('forwardNote', 'لطفا بررسی شود')
            ->call('forward');

        // The existing forward notification is unchanged, and the new
        // assignment type never fires on this path.
        $this->assertSame(1, $this->notificationsOfTypeFor('ticket_forwarded', $recipient->id));
        $this->assertSame(0, $this->notificationsOfType('ticket_assigned'));
    }

    // =====================================================================
    // creator == assignee on the accept step
    // =====================================================================

    public function test_assign_to_creator_then_accept_by_creator_sends_only_assignment(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets'], 'admin');
        $creator = $this->teammateOnUnit($unit);
        $ticket = $this->makeTicket($unit, $creator);

        $actorToken = $this->createApiToken($actor, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/assign", ['assignee_id' => $creator->id], $actorToken)
            ->assertStatus(200);

        $creator->givePermissionTo('create_ticket');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $creatorToken = $this->createApiToken($creator, ['tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $creatorToken)
            ->assertStatus(200);

        $this->assertSame(1, $this->notificationsOfTypeFor('ticket_assigned', $creator->id));
        $this->assertSame(0, $this->notificationsOfType('ticket_accepted'));
    }
}
