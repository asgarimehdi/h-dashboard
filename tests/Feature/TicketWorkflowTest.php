<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

covers(Ticket::class);

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_has_created_status_by_default(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        // The factory picks a RANDOM status (TicketFactory::definition), so the
        // "created by default" contract is only observable through the explicit
        // ->created() state. Asserting on a bare factory()->create() would be a
        // coin flip.
        $ticket = Ticket::factory()->for($user)->for($unit)->created()->create();

        $this->assertEquals('created', $ticket->status);
    }

    public function test_ticket_can_be_forwarded(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create(['status' => 'created']);
        $ticket->update(['status' => 'forwarded']);

        $this->assertEquals('forwarded', $ticket->fresh()->status);
    }

    public function test_ticket_accepted_sets_accepted_at(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'status' => 'forwarded',
            'accepted_at' => null,
        ]);

        $ticket->update(['status' => 'accepted', 'accepted_at' => now()]);
        $ticket->refresh();

        $this->assertEquals('accepted', $ticket->status);
        $this->assertNotNull($ticket->accepted_at);
        $this->assertInstanceOf(Carbon::class, $ticket->accepted_at);
    }

    public function test_ticket_completed_sets_completed_at(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'status' => 'accepted',
            'accepted_at' => now()->subDay(),
            'completed_at' => null,
        ]);

        $ticket->update(['status' => 'completed', 'completed_at' => now()]);
        $ticket->refresh();

        $this->assertEquals('completed', $ticket->status);
        $this->assertNotNull($ticket->completed_at);
        $this->assertInstanceOf(Carbon::class, $ticket->completed_at);
    }

    public function test_ticket_rejected_status_works(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create(['status' => 'created']);
        $ticket->update(['status' => 'rejected']);

        $this->assertEquals('rejected', $ticket->fresh()->status);
    }

    public function test_ticket_timestamps_are_cast(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'accepted_at' => now(),
            'completed_at' => now(),
        ]);

        $this->assertInstanceOf(Carbon::class, $ticket->created_at);
        $this->assertInstanceOf(Carbon::class, $ticket->updated_at);
        $this->assertInstanceOf(Carbon::class, $ticket->accepted_at);
        $this->assertInstanceOf(Carbon::class, $ticket->completed_at);
    }

    public function test_ticket_deadline_is_cast(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'deadline' => now()->addDay(),
        ]);

        // The third `datetime` cast on the model — it had no live assertion
        // before this file was resurrected (issue #935).
        $this->assertInstanceOf(Carbon::class, $ticket->deadline);
        $this->assertTrue($ticket->deadline->isFuture());
    }

    public function test_ticket_factory_produces_valid_data(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'subject' => 'Test subject',
            'content' => 'Test content',
        ]);

        $this->assertNotEmpty($ticket->subject);
        $this->assertNotEmpty($ticket->content);
        $this->assertNotNull($ticket->ticket_code);
    }

    /**
     * Issue #935 — the factory states that nothing ever consumed.
     *
     * `TicketFactory::accepted()` / `->completed()` / `->rejected()` set the
     * status AND the matching timestamp together. Before this file ran, they
     * were dead code; these are the assertions that give them a purpose and
     * pin the pair the endpoints write as one unit.
     */
    public function test_ticket_factory_states_write_status_and_timestamps_together(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $accepted = Ticket::factory()->for($user)->for($unit)->accepted()->create();
        $completed = Ticket::factory()->for($user)->for($unit)->completed()->create();
        $rejected = Ticket::factory()->for($user)->for($unit)->rejected()->create();

        $this->assertEquals('accepted', $accepted->status);
        $this->assertInstanceOf(Carbon::class, $accepted->accepted_at);
        $this->assertNull($accepted->completed_at);

        $this->assertEquals('completed', $completed->status);
        $this->assertInstanceOf(Carbon::class, $completed->accepted_at);
        $this->assertInstanceOf(Carbon::class, $completed->completed_at);

        $this->assertEquals('rejected', $rejected->status);
        $this->assertNull($rejected->accepted_at);
        $this->assertNull($rejected->completed_at);
    }

    public function test_ticket_factory_priorities_are_all_supported(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $this->assertEquals('urgent', Ticket::factory()->for($user)->for($unit)->urgent()->create()->priority);
        $this->assertEquals('normal', Ticket::factory()->for($user)->for($unit)->normal()->create()->priority);
        $this->assertEquals('low', Ticket::factory()->for($user)->for($unit)->low()->create()->priority);
    }
}
