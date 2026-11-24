<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1728 — "open ticket" had six different spellings. The dashboard used
 * `created + forwarded` (dropping every `accepted` ticket), while the daily
 * report, GIS and the auto-complete paths used `status != 'completed'`
 * (counting every `rejected` ticket). `Ticket::open()` / `Ticket::openSql()`
 * are the single definition now: `created`/`forwarded`/`accepted`, not
 * archived. These tests pin the dashboard side of that contract.
 */
class DashboardOpenTicketDefinitionTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private User $user;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        ['user' => $this->user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeTicket(string $code, array $attributes): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_code' => $code,
            'unit_id' => $this->unit->id,
            'user_id' => $this->user->id,
            'subject' => $code,
            'content' => 'Body',
            'priority' => 'normal',
            'status' => 'created',
        ], $attributes));
    }

    private function mountDashboard(): Testable
    {
        return Livewire::test('dashboard');
    }

    public function test_accepted_tickets_count_as_open(): void
    {
        $this->makeTicket('T-OPEN-ACCEPTED', ['status' => 'accepted']);
        $component = $this->mountDashboard();

        $this->assertSame(1, $component->get('openTickets'));
    }

    public function test_rejected_tickets_do_not_count_as_open(): void
    {
        $this->makeTicket('T-OPEN-REJECTED', ['status' => 'rejected']);
        $component = $this->mountDashboard();

        $this->assertSame(0, $component->get('openTickets'));
    }

    public function test_an_archived_ticket_is_not_open_even_with_an_open_status(): void
    {
        $this->makeTicket('T-OPEN-ARCHIVED', [
            'status' => 'created',
            'archived_at' => now(),
        ]);
        $component = $this->mountDashboard();

        $this->assertSame(0, $component->get('openTickets'), 'archived_at IS NULL is load-bearing, not defensive');
    }

    public function test_an_accepted_ticket_past_its_deadline_is_overdue(): void
    {
        $this->makeTicket('T-OVERDUE-ACCEPTED', [
            'status' => 'accepted',
            'deadline' => now()->subDay(),
        ]);
        $this->makeTicket('T-OVERDUE-FUTURE', [
            'status' => 'forwarded',
            'deadline' => now()->addDay(),
        ]);
        $this->makeTicket('T-OVERDUE-NONE', [
            'status' => 'created',
            'deadline' => null,
        ]);

        $component = $this->mountDashboard();

        $this->assertSame(3, $component->get('openTickets'));
        $this->assertSame(1, $component->get('overdueTickets'), 'only the accepted ticket is past its deadline');
    }

    public function test_priority_buckets_sum_to_open_tickets_when_accepted_is_present(): void
    {
        $this->makeTicket('T-BUCKET-ACCEPTED-URGENT', ['status' => 'accepted', 'priority' => 'urgent']);
        $this->makeTicket('T-BUCKET-ACCEPTED-NORMAL', ['status' => 'accepted', 'priority' => 'normal']);
        $this->makeTicket('T-BUCKET-FORWARDED-LOW', ['status' => 'forwarded', 'priority' => 'low']);
        $this->makeTicket('T-BUCKET-REJECTED-URGENT', ['status' => 'rejected', 'priority' => 'urgent']);

        $component = $this->mountDashboard();

        $bucketSum = $component->get('urgentTickets')
            + $component->get('normalTickets')
            + $component->get('lowTickets');

        $this->assertSame(3, $component->get('openTickets'));
        $this->assertSame($component->get('openTickets'), $bucketSum);
    }
}
