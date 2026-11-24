<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\TaskActivity;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1699 (phase 1) — `TicketTransitionService` is the single owner of the
 * accept/complete transitions. These tests run the SAME scenario through the
 * web (Livewire) and the API entry point and compare a normalised snapshot of
 * the durable state, so a guard that only one adapter applies fails here.
 *
 * The service normalises the locked-row predicates and lets each adapter keep
 * its own message order (D1). The one deliberate divergence that survives is
 * D4: the web accept path also writes an `activity_logs` audit row while the
 * API path never has. It is asserted explicitly below so the follow-up issue
 * has a failing marker the day it is unified.
 */
class TicketTransitionParityTest extends TestCase
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
     * @param  array<string>  $permissions
     * @return array{user: User, unit: Unit}
     */
    private function actor(array $permissions = ['view_assigned_tickets', 'create_ticket', 'manage_unit_tickets']): array
    {
        return $this->createUserWithUnit($permissions, 'admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function ticket(User $user, Unit $unit, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_code' => 'T'.fake()->unique()->numerify('######'),
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Parity fixture',
            'content' => 'Body',
            'priority' => 'normal',
            'status' => 'forwarded',
            'current_assignee_id' => $user->id,
        ], $overrides));
    }

    private function teammateOnUnit(Unit $unit): User
    {
        $person = Person::factory()->create(['u_id' => $unit->id]);
        $user = User::factory()->create(['n_code' => $person->n_code]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    private function token(User $user): string
    {
        return $this->createApiToken($user, ['tickets:read', 'tickets:write']);
    }

    /**
     * The durable state both entry points must agree on. Ids and timestamps
     * are reduced to what is stable across runs; `activity_logs` is compared
     * separately because of the documented D4 divergence.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Ticket $ticket): array
    {
        $ticket->refresh();

        $activities = TaskActivity::query()
            ->where('ticket_id', $ticket->id)
            ->orderBy('id')
            ->get()
            ->map(fn (TaskActivity $activity): array => [
                'action' => $activity->action,
                'to_unit_id' => $activity->to_unit_id === null ? null : (int) $activity->to_unit_id,
            ])
            ->all();

        return [
            'status' => $ticket->status,
            'unit_id' => (int) $ticket->unit_id,
            'assignee' => $ticket->current_assignee_id === null ? null : (int) $ticket->current_assignee_id,
            'accepted_at' => $ticket->accepted_at !== null,
            'completed_at' => $ticket->completed_at !== null,
            'activities' => $activities,
            'task_completed' => $ticket->task_id === null ? null : (bool) $ticket->task?->is_completed,
            'notifications' => DB::table('notifications')->count(),
        ];
    }

    private function auditRows(Ticket $ticket): int
    {
        return DB::table('activity_logs')
            ->where('subject_type', Ticket::class)
            ->where('subject_id', $ticket->id)
            ->count();
    }

    /**
     * @param  object|null  $row  the row every `FOR UPDATE` probe reports
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

    public function test_accept_reaches_the_same_state_from_web_and_api(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->actor();

        $apiTicket = $this->ticket($actor, $unit, ['status' => 'forwarded']);
        $webTicket = $this->ticket($actor, $unit, ['status' => 'forwarded']);

        $this->apiPost("/api/tickets/{$apiTicket->id}/accept", [], $this->token($actor))
            ->assertStatus(200);

        $this->actingAs($actor);
        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $webTicket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'تیکت پذیرفته شد';
            });

        $api = $this->snapshot($apiTicket);
        $web = $this->snapshot($webTicket);

        $this->assertSame($api, $web, 'Accept must leave the same durable state on both entry points.');
        $this->assertSame('accepted', $api['status']);
        $this->assertSame((int) $actor->id, $api['assignee']);
        $this->assertTrue($api['accepted_at']);
        $this->assertFalse($api['completed_at']);
        $this->assertSame([['action' => 'accepted', 'to_unit_id' => null]], $api['activities']);

        // D4 (binding): audit unification is a follow-up, so the divergence is
        // pinned rather than silently lost.
        $this->assertSame(0, $this->auditRows($apiTicket));
        $this->assertSame(1, $this->auditRows($webTicket));
    }

    public function test_complete_reaches_the_same_state_from_web_and_api(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->actor();

        $apiTodo = Todo::create([
            'title' => 'API parity todo',
            'start_at' => Carbon::now(),
            'is_completed' => false,
            'unit_id' => $unit->id,
        ]);
        $webTodo = Todo::create([
            'title' => 'Web parity todo',
            'start_at' => Carbon::now(),
            'is_completed' => false,
            'unit_id' => $unit->id,
        ]);

        $apiTicket = $this->ticket($actor, $unit, ['status' => 'accepted', 'task_id' => $apiTodo->id]);
        $webTicket = $this->ticket($actor, $unit, ['status' => 'accepted', 'task_id' => $webTodo->id]);

        $this->apiPost("/api/tickets/{$apiTicket->id}/complete", [], $this->token($actor))
            ->assertStatus(200);

        $this->actingAs($actor);
        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $webTicket->id)
            ->set('completionNote', 'گزارش نهایی هم‌ارزی')
            ->call('submitAction')
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'عملیات موفق';
            });

        $api = $this->snapshot($apiTicket);
        $web = $this->snapshot($webTicket);

        $this->assertSame($api, $web, 'Complete must leave the same durable state on both entry points.');
        $this->assertSame('completed', $api['status']);
        $this->assertTrue($api['completed_at']);
        $this->assertSame([['action' => 'completed', 'to_unit_id' => (int) $unit->id]], $api['activities']);
        $this->assertTrue($api['task_completed']);

        // Both completion paths leave the audit trail alone (D4).
        $this->assertSame(0, $this->auditRows($apiTicket));
        $this->assertSame(0, $this->auditRows($webTicket));
    }

    public function test_archived_ticket_cannot_be_accepted_from_either_entry_point(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->actor();

        $archived = ['status' => 'forwarded', 'archived_at' => Carbon::now()];
        $apiTicket = $this->ticket($actor, $unit, $archived);
        $webTicket = $this->ticket($actor, $unit, $archived);

        $this->apiPost("/api/tickets/{$apiTicket->id}/accept", [], $this->token($actor))
            ->assertStatus(422)
            ->assertJson(['message' => 'Only created or forwarded tickets can be accepted.']);

        $this->actingAs($actor);
        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $webTicket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'تیکت قبلاً پذیرفته شده است';
            });

        $api = $this->snapshot($apiTicket);
        $web = $this->snapshot($webTicket);

        $this->assertSame($api, $web);
        $this->assertSame('forwarded', $api['status']);
        $this->assertFalse($api['accepted_at']);
        $this->assertSame([], $api['activities']);
        $this->assertSame(0, $this->auditRows($apiTicket));
        $this->assertSame(0, $this->auditRows($webTicket));
    }

    public function test_archived_ticket_cannot_be_completed_from_either_entry_point(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->actor();

        $archived = ['status' => 'accepted', 'archived_at' => Carbon::now()];
        $apiTicket = $this->ticket($actor, $unit, $archived);
        $webTicket = $this->ticket($actor, $unit, $archived);

        $this->apiPost("/api/tickets/{$apiTicket->id}/complete", [], $this->token($actor))
            ->assertStatus(422)
            ->assertJson(['message' => 'Ticket must be accepted before completing.']);

        $this->actingAs($actor);
        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $webTicket->id)
            ->set('completionNote', 'گزارش نهایی آرشیو')
            ->call('submitAction')
            ->assertHasErrors(['completionNote']);

        $api = $this->snapshot($apiTicket);
        $web = $this->snapshot($webTicket);

        $this->assertSame($api, $web);
        $this->assertSame('accepted', $api['status']);
        $this->assertFalse($api['completed_at']);
        $this->assertSame([], $api['activities']);
    }

    public function test_status_race_loses_identically_on_both_entry_points(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->actor();

        $apiTicket = $this->ticket($actor, $unit, ['status' => 'created']);
        $webTicket = $this->ticket($actor, $unit, ['status' => 'created']);

        // The row moved on between the adapter's read and the service's lock.
        $this->fakeLockedTicketRow((object) [
            'id' => $apiTicket->id,
            'status' => 'accepted',
            'current_assignee_id' => $actor->id,
            'archived_at' => null,
        ]);

        $this->apiPost("/api/tickets/{$apiTicket->id}/accept", [], $this->token($actor))
            ->assertStatus(422);

        $this->actingAs($actor);
        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $webTicket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'تیکت قبلاً پذیرفته شده است';
            });

        $this->assertSame('created', $apiTicket->refresh()->status);
        $this->assertSame('created', $webTicket->refresh()->status);
        $this->assertSame([], $this->snapshot($apiTicket)['activities']);
        $this->assertSame([], $this->snapshot($webTicket)['activities']);
    }

    public function test_assignee_race_loses_identically_on_both_entry_points(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->actor();

        $teammate = $this->teammateOnUnit($unit);

        $apiTicket = $this->ticket($actor, $unit, ['status' => 'forwarded']);
        $webTicket = $this->ticket($actor, $unit, ['status' => 'forwarded']);

        // Same status, different assignee: the seizure #529 exists to prevent.
        $this->fakeLockedTicketRow((object) [
            'id' => $apiTicket->id,
            'status' => 'forwarded',
            'current_assignee_id' => $teammate->id,
            'archived_at' => null,
        ]);

        $this->apiPost("/api/tickets/{$apiTicket->id}/accept", [], $this->token($actor))
            ->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can accept this ticket.']);

        $this->actingAs($actor);
        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $webTicket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'تیکت قبلاً پذیرفته شده است';
            });

        $api = $this->snapshot($apiTicket);
        $web = $this->snapshot($webTicket);

        $this->assertSame($api, $web);
        $this->assertSame('forwarded', $api['status']);
        $this->assertSame((int) $actor->id, $api['assignee']);
        $this->assertSame([], $api['activities']);
    }

    public function test_non_assignee_cannot_accept_from_either_entry_point(): void
    {
        ['user' => $actor, 'unit' => $unit] = $this->actor();

        $teammate = $this->teammateOnUnit($unit);

        $assigned = ['status' => 'forwarded', 'current_assignee_id' => $teammate->id];
        $apiTicket = $this->ticket($actor, $unit, $assigned);
        $webTicket = $this->ticket($actor, $unit, $assigned);

        $this->apiPost("/api/tickets/{$apiTicket->id}/accept", [], $this->token($actor))
            ->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can accept this ticket.']);

        $this->actingAs($actor);
        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $webTicket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'فقط کارشناس ارجاع‌شده می‌تواند این تیکت را بپذیرد.';
            });

        $api = $this->snapshot($apiTicket);
        $web = $this->snapshot($webTicket);

        $this->assertSame($api, $web);
        $this->assertSame('forwarded', $api['status']);
        $this->assertSame((int) $teammate->id, $api['assignee']);
        $this->assertSame([], $api['activities']);
    }
}
