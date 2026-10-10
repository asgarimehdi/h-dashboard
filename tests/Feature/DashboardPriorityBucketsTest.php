<?php

namespace Tests\Feature;

use App\Models\MaintenanceSchedule;
use App\Models\Ticket;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Ticket::class);

/**
 * Issue #954 — the dashboard's three priority tiles and its open-tickets
 * tile must agree. The tiles count `urgent`/`normal`/`low` over open
 * tickets; the open tile counts open tickets regardless of priority. When a
 * value outside the three (the nightly `medium`) entered the scope, the sum
 * of the tiles silently fell short of the open count — a disagreement no
 * single tile could show. This equality is the drift guard: it fails for
 * ANY stored priority no bucket reads, now and in the future.
 */
class DashboardPriorityBucketsTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_priority_tiles_sum_to_open_tickets_with_a_generated_maintenance_ticket(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        // The regression shape: a maintenance schedule becomes due and the
        // unattended 03:00 command writes its ticket into the scope.
        MaintenanceSchedule::create([
            'unit_id' => $unit->id,
            'title' => 'Nightly generator check',
            'frequency' => 'monthly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->subDay(),
        ]);
        $this->artisan('maintenance:generate-due')->assertExitCode(0);

        // A second open ticket with a different bucket, so the equality is
        // not vacuously true for a single-ticket scope.
        Ticket::create([
            'ticket_code' => 'T-BUCKETS-URGENT',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Urgent open ticket',
            'content' => 'Body',
            'priority' => 'urgent',
            'status' => 'forwarded',
        ]);

        // A completed ticket: open on neither side of the equality.
        Ticket::create([
            'ticket_code' => 'T-BUCKETS-DONE',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Completed ticket',
            'content' => 'Body',
            'priority' => 'low',
            'status' => 'completed',
        ]);

        $component = Livewire::test('dashboard');

        // Both sides share the same "open" definition: created + forwarded.
        $this->assertSame(2, $component->get('openTickets'));

        $bucketSum = $component->get('urgentTickets')
            + $component->get('normalTickets')
            + $component->get('lowTickets');

        $this->assertSame($component->get('openTickets'), $bucketSum);
    }
}
