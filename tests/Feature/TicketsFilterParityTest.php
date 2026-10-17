<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1055 (step 1) — the shared ticket-filter trait.
 *
 * `tickets.inbox` and `tickets.monitoring` carried the status/date/search
 * filter block line-for-line in each component. These tests pin both pages to
 * the SAME filtered result set so a future edit to one cannot drift from the
 * other.
 *
 * Parity is asserted on the same base scope on both pages: the inbox in
 * `viewMode = 'received'` is `->accessible()`, which is also monitoring's base
 * query when no unit is selected.
 */
class TicketsFilterParityTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, int>
     */
    private function inboxIds(array $state): array
    {
        // `all` is the shared baseline: the inbox defaults to `pending`, which
        // would silently filter the accepted/completed/rejected fixtures out.
        $test = Livewire::test('tickets.inbox')->set('viewMode', 'received')->set('statusFilter', 'all');
        foreach ($state as $key => $value) {
            $test->set($key, $value);
        }

        return $test->instance()->tickets()->pluck('id')->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, int>
     */
    private function monitoringIds(array $state): array
    {
        $test = Livewire::test('tickets.monitoring')->set('statusFilter', 'all');
        foreach ($state as $key => $value) {
            $test->set($key, $value);
        }

        return $test->instance()->tickets()->pluck('id')->sort()->values()->all();
    }

    /**
     * One ticket per status, each with a searchable subject and unique code.
     *
     * @return array<string, Ticket>
     */
    private function seedStatusTickets(Unit $unit): array
    {
        $rows = [
            'created' => ['تیکت آلفا', 'PAR-CODE-1'],
            'forwarded' => ['تیکت بتا', 'PAR-CODE-2'],
            'accepted' => ['تیکت گاما', 'PAR-CODE-3'],
            'completed' => ['تیکت دلتا', 'PAR-CODE-4'],
            'rejected' => ['تیکت اپسیلون', 'PAR-CODE-5'],
        ];

        $tickets = [];
        foreach ($rows as $status => [$subject, $code]) {
            $tickets[$status] = Ticket::create([
                'ticket_code' => $code,
                'unit_id' => $unit->id,
                'subject' => $subject,
                'content' => 'متن عمومی',
                'priority' => 'normal',
                'status' => $status,
            ]);
        }

        return $tickets;
    }

    private function actor(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(
            ['view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets']
        );
        $this->actingAs($user);
    }

    public function test_status_filter_is_identical_across_inbox_and_monitoring(): void
    {
        $this->actor();
        $unit = auth()->user()->units()->first();
        $this->seedStatusTickets($unit);

        foreach (['pending', 'all', 'created', 'forwarded', 'accepted', 'completed', 'rejected'] as $status) {
            $this->assertSame(
                $this->monitoringIds(['statusFilter' => $status]),
                $this->inboxIds(['statusFilter' => $status]),
                "Status filter '{$status}' differs between inbox and monitoring."
            );
        }
    }

    public function test_search_filter_is_identical_across_inbox_and_monitoring(): void
    {
        $this->actor();
        $unit = auth()->user()->units()->first();
        $this->seedStatusTickets($unit);

        // Terms that match subject and ticket_code — the two columns both pages
        // search. (`content` is searched by the inbox only; that difference is
        // intentional and out of scope here.)
        foreach (['آلفا', 'PAR-CODE-2', 'تیکت گاما'] as $term) {
            $this->assertSame(
                $this->monitoringIds(['search' => $term]),
                $this->inboxIds(['search' => $term]),
                "Search term '{$term}' differs between inbox and monitoring."
            );
        }
    }

    public function test_date_range_filter_is_identical_across_inbox_and_monitoring(): void
    {
        $this->actor();
        $unit = auth()->user()->units()->first();

        $old = Ticket::create([
            'ticket_code' => 'PAR-OLD', 'unit_id' => $unit->id, 'subject' => 'قدیمی',
            'content' => 'متن عمومی', 'priority' => 'normal', 'status' => 'created',
        ]);
        $old->forceFill(['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)])->save();

        $recent = Ticket::create([
            'ticket_code' => 'PAR-NEW', 'unit_id' => $unit->id, 'subject' => 'جدید',
            'content' => 'متن عمومی', 'priority' => 'normal', 'status' => 'created',
        ]);
        $recent->forceFill(['created_at' => now(), 'updated_at' => now()])->save();

        $from = Jalalian::fromCarbon(now()->subDays(5))->format('Y/m/d');
        $to = Jalalian::fromCarbon(now()->addDay())->format('Y/m/d');

        $state = ['dateFrom' => $from, 'dateTo' => $to];

        $this->assertSame(
            $this->monitoringIds($state),
            $this->inboxIds($state),
            'Date-range filter differs between inbox and monitoring.'
        );

        // Sanity: the window really did exclude the old ticket.
        $this->assertNotContains($old->id, $this->inboxIds($state));
        $this->assertContains($recent->id, $this->inboxIds($state));
    }
}
